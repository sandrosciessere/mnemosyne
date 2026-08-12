<?php

namespace Tests\Support;

use App\Models\BookAsset;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\Chunker;
use Illuminate\Support\Facades\Storage;

/**
 * Builds synthetic Milestone-1-shaped spine artifacts (JSONL nodes +
 * canonical.txt) on the fake data disk, with offset semantics identical
 * to the worker: canonical = corpus node texts joined by '\n', codepoint
 * and UTF-16 offsets computed over that corpus.
 */
trait BuildsRetrievalArtifacts
{
    /**
     * @param  array<int, list<array{type?: string, text: string, heading_path?: array, fragment?: string|null}>>  $docs
     *                                                                                                                    spine_index => ordered node specs
     * @return array{asset: BookAsset, canonical: string}
     */
    protected function buildArtifacts(array $docs, ?BookAsset $asset = null): array
    {
        $asset = $asset ?? BookAsset::factory()->readyForEnrichment()->create();

        $canonical = '';
        $utf16Cursor = 0;
        $ordinal = 0;
        $disk = Storage::disk('data');
        $dir = $asset->artifactDir($asset->pipeline_version ?? '1');

        foreach ($docs as $spineIndex => $nodes) {
            $lines = [];

            foreach ($nodes as $spec) {
                $text = $spec['text'];
                $isCorpus = ($spec['corpus'] ?? true) && $text !== '';

                if ($isCorpus) {
                    if ($canonical !== '') {
                        $canonical .= "\n";
                        $utf16Cursor += 1;
                    }
                    $start = mb_strlen($canonical);
                    $startUtf16 = $utf16Cursor;
                    $canonical .= $text;
                    $utf16Cursor += Chunker::utf16Length($text);
                    $end = mb_strlen($canonical);
                    $endUtf16 = $utf16Cursor;
                } else {
                    $start = $end = $startUtf16 = $endUtf16 = null;
                }

                $node = [
                    'node_id' => sprintf('n%04d-%06d', $spineIndex, $ordinal),
                    'spine_index' => $spineIndex,
                    'ordinal' => $ordinal,
                    'type' => $spec['type'] ?? 'paragraph',
                    'level' => null,
                    'text' => $text,
                    'heading_path' => $spec['heading_path'] ?? [],
                    'source' => [
                        'href' => "OEBPS/doc{$spineIndex}.xhtml",
                        'fragment' => $spec['fragment'] ?? null,
                    ],
                    'lang' => null,
                    'linear' => true,
                    'char_count' => mb_strlen($text),
                    'has_image' => ! $isCorpus,
                    'is_note' => false,
                    'refs' => null,
                    'table' => null,
                    'image' => null,
                    'source_hash' => hash('sha256', "OEBPS/doc{$spineIndex}.xhtml\0".($spec['fragment'] ?? '')."\0".($spec['type'] ?? 'paragraph')."\0".$text),
                    'normalized_start' => $start,
                    'normalized_end' => $end,
                    'normalized_start_utf16' => $startUtf16,
                    'normalized_end_utf16' => $endUtf16,
                ];

                $lines[] = json_encode($node, JSON_UNESCAPED_UNICODE);
                $ordinal++;
            }

            $disk->put(sprintf('%s/spine/%04d.jsonl', $dir, $spineIndex), implode("\n", $lines)."\n");
        }

        $disk->put($dir.'/canonical.txt', $canonical);
        $asset->forceFill(['content_sha256' => hash('sha256', $canonical)])->save();

        return ['asset' => $asset, 'canonical' => $canonical];
    }

    /** Generation wired to the deterministic test embedding provider. */
    protected function makeTestGeneration(string $status = 'building'): RetrievalGeneration
    {
        $chunkerConfig = ['target_chars' => 300, 'min_chars' => 80, 'max_chars' => 500, 'overlap_tail_chars' => 60];

        $generation = new RetrievalGeneration;
        $generation->forceFill([
            'status' => $status,
            'config' => [
                'chunker' => ['version' => '1.0.0', 'config' => $chunkerConfig],
                'query_normalization_version' => '1.0.0',
                'lexical' => ['version' => '1.1.0', 'config' => 'simple'],
                'embedding' => [
                    'provider' => 'test',
                    'model_key' => 'deterministic-test',
                    'dimensions' => 32,
                    'metric' => 'cosine',
                    'normalized' => true,
                    'batch_size' => 8,
                ],
                'fusion' => ['algorithm' => 'rrf', 'version' => '1.0.0', 'k' => 60, 'weights' => ['exact' => 2.0, 'lexical' => 1.0, 'dense' => 1.0]],
                'reranker' => ['provider' => 'test', 'model_key' => 'none'],
                'ann' => config('mnemosyne.retrieval.ann'),
            ],
            'chunker_config_hash' => hash('sha256', json_encode(['1.0.0', $chunkerConfig])),
            'chunker_version' => '1.0.0',
            'embedding_model_key' => 'deterministic-test',
            'embedding_dimensions' => 32,
        ])->save();

        return $generation;
    }

    /** UTF-16 slice helper mirroring the future JS reader semantics. */
    protected function utf16Slice(string $text, int $start, int $end): string
    {
        $bytes = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(substr($bytes, $start * 2, ($end - $start) * 2), 'UTF-8', 'UTF-16LE');
    }
}
