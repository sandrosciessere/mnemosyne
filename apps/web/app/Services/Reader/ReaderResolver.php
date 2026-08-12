<?php

namespace App\Services\Reader;

use App\Models\BookAsset;
use App\Models\GroundedAnswerEvidence;
use App\Services\Library\LibraryStorage;
use League\Flysystem\FilesystemException;

/**
 * Evidence Reader v1 backend: renders normalized M1 canonical
 * structures (safe text nodes, never raw EPUB XHTML) and resolves
 * answer evidence to exact node-relative UTF-16 highlight ranges.
 *
 * Resolution path is answer evidence → canonical coordinates → current
 * artifacts; NEVER through the retrieval generation. Staleness is
 * fail-closed on two levels: the asset content fingerprint (book
 * re-ingested) and the per-node source_hash (node text changed) — a
 * possibly-wrong highlight is never shown.
 */
class ReaderResolver
{
    public function __construct(private readonly LibraryStorage $storage) {}

    /**
     * Section navigation from structure.json (spine + toc labels).
     *
     * @return list<array{spine_index: int, href: string, label: ?string, char_count: int}>
     */
    public function sections(BookAsset $asset): array
    {
        $structure = $this->readJson($asset, 'structure.json');

        if ($structure === null) {
            return [];
        }

        $labels = [];
        $this->collectTocLabels($structure['toc'] ?? [], $labels);

        $sections = [];

        foreach ($structure['spine'] ?? [] as $entry) {
            $href = (string) ($entry['href'] ?? '');

            $sections[] = [
                'spine_index' => (int) ($entry['spine_index'] ?? 0),
                'href' => $href,
                'label' => $labels[$href] ?? null,
                'char_count' => (int) ($entry['char_count'] ?? 0),
            ];
        }

        return $sections;
    }

    /**
     * Safe render payload for one spine document: typed text nodes only.
     *
     * @return list<array{id: string, type: string, level: ?int, text: string, utf16_start: ?int}>|null
     */
    public function section(BookAsset $asset, int $spineIndex): ?array
    {
        $nodes = $this->readSpineNodes($asset, $spineIndex);

        if ($nodes === null) {
            return null;
        }

        return array_values(array_map(fn (array $node) => [
            'id' => (string) $node['node_id'],
            'type' => (string) ($node['type'] ?? 'paragraph'),
            'level' => $node['level'] ?? null,
            'text' => (string) ($node['text'] ?? ''),
            'utf16_start' => $node['normalized_start_utf16'] ?? null,
        ], $nodes));
    }

    /**
     * Resolves one answer evidence row to an exact highlight in the
     * current artifacts.
     *
     * @return array{status: string, spine_index: ?int, node_id: ?string,
     *               utf16_start: ?int, utf16_end: ?int}
     */
    public function resolveEvidence(GroundedAnswerEvidence $evidence): array
    {
        $asset = $evidence->asset;

        if ($asset === null) {
            return ['status' => 'ASSET_REMOVED', 'spine_index' => null, 'node_id' => null, 'utf16_start' => null, 'utf16_end' => null];
        }

        if ($asset->content_sha256 !== $evidence->source_content_sha256) {
            // The book was re-ingested since this answer: coordinates
            // may point anywhere in the new canonical text. Fail closed.
            return ['status' => 'CITATION_SOURCE_CHANGED', 'spine_index' => null, 'node_id' => null, 'utf16_start' => null, 'utf16_end' => null];
        }

        $nodes = $this->readSpineNodes($asset, $evidence->spine_index);

        if ($nodes === null) {
            return ['status' => 'SOURCE_ARTIFACTS_MISSING', 'spine_index' => null, 'node_id' => null, 'utf16_start' => null, 'utf16_end' => null];
        }

        foreach ($nodes as $node) {
            if (($node['node_id'] ?? null) !== $evidence->source_node_id) {
                continue;
            }

            if ($evidence->source_hash !== null && ($node['source_hash'] ?? null) !== $evidence->source_hash) {
                return ['status' => 'CITATION_SOURCE_CHANGED', 'spine_index' => null, 'node_id' => null, 'utf16_start' => null, 'utf16_end' => null];
            }

            $nodeStart = (int) ($node['normalized_start_utf16'] ?? 0);

            return [
                'status' => 'ok',
                'spine_index' => $evidence->spine_index,
                'node_id' => $evidence->source_node_id,
                // Node-relative UTF-16 code-unit offsets: exactly what
                // JavaScript string slicing understands.
                'utf16_start' => $evidence->utf16_start - $nodeStart,
                'utf16_end' => $evidence->utf16_end - $nodeStart,
            ];
        }

        return ['status' => 'CITATION_SOURCE_CHANGED', 'spine_index' => null, 'node_id' => null, 'utf16_start' => null, 'utf16_end' => null];
    }

    /** @return list<array>|null decoded node lines */
    private function readSpineNodes(BookAsset $asset, int $spineIndex): ?array
    {
        $path = sprintf(
            '%s/spine/%04d.jsonl',
            $asset->artifactDir($asset->pipeline_version ?? '1'),
            $spineIndex,
        );

        try {
            if (! $this->storage->disk()->exists($path)) {
                return null;
            }

            $raw = $this->storage->disk()->get($path);
        } catch (FilesystemException) {
            return null;
        }

        $nodes = [];

        foreach (explode("\n", (string) $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $node = json_decode($line, true);

            if (is_array($node)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function readJson(BookAsset $asset, string $name): ?array
    {
        $path = $asset->artifactDir($asset->pipeline_version ?? '1').'/'.$name;

        try {
            if (! $this->storage->disk()->exists($path)) {
                return null;
            }

            $decoded = json_decode((string) $this->storage->disk()->get($path), true);
        } catch (FilesystemException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, string> $labels href => label */
    private function collectTocLabels(array $entries, array &$labels): void
    {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $href = (string) ($entry['href'] ?? '');
            $href = explode('#', $href, 2)[0];
            $label = $entry['label'] ?? $entry['title'] ?? null;

            if ($href !== '' && is_string($label) && ! isset($labels[$href])) {
                $labels[$href] = mb_substr($label, 0, 200);
            }

            $this->collectTocLabels($entry['children'] ?? [], $labels);
        }
    }
}
