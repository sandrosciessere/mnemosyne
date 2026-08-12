<?php

namespace App\Services\Answers;

use App\Models\RetrievalChunk;
use App\Services\Retrieval\Chunker;

/**
 * evidence-unitizer 1.0.0 — deterministically converts M2 retrieval
 * candidates (chunks + EvidenceSpans) into bounded citeable
 * EvidenceUnits.
 *
 * Every unit is an exact canonical slice derived from span provenance:
 * a span at most unit_max_chars long becomes one unit; longer spans are
 * split at sentence boundaries (codepoints, never bytes), each window
 * carrying recomputed canonical AND UTF-16 offsets. Chunk-local
 * synthetic separators ('\n' between non-contiguous spans) are never
 * part of any unit — units come from spans, and spans are 100%
 * source-backed by the M2 invariant.
 */
class EvidenceUnitizer
{
    /**
     * 1.1.0 adds deterministic sentence-level source atoms (S1..Sn)
     * inside each unit: the minimal citeable CitationSpans the verifier
     * must select from. Unit semantics are unchanged from 1.0.0.
     */
    public const VERSION = 'evidence-unitizer 1.1.0';

    public function __construct(private readonly int $unitMaxChars) {}

    /**
     * @return list<EvidenceUnit> in span order for this chunk
     */
    public function unitsForChunk(RetrievalChunk $chunk, array $retrievalMeta): array
    {
        $units = [];
        $asset = $chunk->asset;

        foreach ($chunk->spans as $span) {
            $spanText = mb_substr(
                $chunk->source_text,
                $span->chunk_start,
                $span->chunk_end - $span->chunk_start,
            );

            foreach ($this->windows($spanText) as [$offset, $window]) {
                $prefix = mb_substr($spanText, 0, $offset);
                $utf16Offset = Chunker::utf16Length($prefix);

                $units[] = new EvidenceUnit(
                    bookAssetId: $asset->id,
                    bookPublicId: $asset->public_id,
                    bookTitle: $asset->edition?->title ?? $asset->original_filename,
                    workTitle: $asset->edition?->work?->canonical_title,
                    editionLabel: $asset->edition?->subtitle,
                    sourceNodeId: $span->source_node_id,
                    spineIndex: $span->spine_index,
                    sourceHref: $span->href,
                    sourceFragment: $span->fragment,
                    nodeType: $span->node_type,
                    headingPath: $span->heading_path ?? [],
                    canonicalStart: $span->canonical_start + $offset,
                    canonicalEnd: $span->canonical_start + $offset + mb_strlen($window),
                    utf16Start: $span->utf16_start + $utf16Offset,
                    utf16End: $span->utf16_start + $utf16Offset + Chunker::utf16Length($window),
                    sourceHash: $span->source_hash,
                    sourceContentSha256: $asset->content_sha256,
                    text: $window,
                    retrievalMeta: $retrievalMeta + [
                        'chunk_public_id' => $chunk->public_id,
                        'span_ordinal' => $span->span_ordinal,
                    ],
                );

                $this->atomize($units[count($units) - 1]);
            }
        }

        return $units;
    }

    /**
     * Splits a unit into deterministic sentence atoms with exact
     * absolute coordinates. Atoms partition the unit text losslessly;
     * whitespace stays attached to the preceding sentence so offsets
     * remain exact. Sentence-level is the smallest RELIABLE
     * deterministic span (clause-level precision is not worth wrong
     * offsets: correctness > tiny highlights).
     */
    public function atomize(EvidenceUnit $unit): void
    {
        $pieces = preg_split(
            '/(?<=[.!?…])(?=\s)|(?<=[.!?…]["»”\'])(?=\s)/u',
            $unit->text,
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [$unit->text];

        $offset = 0;
        $ordinal = 0;

        foreach ($pieces as $piece) {
            $length = mb_strlen($piece);

            if (trim($piece) !== '') {
                $ordinal++;
                $key = 'S'.$ordinal;
                $utf16Offset = Chunker::utf16Length(mb_substr($unit->text, 0, $offset));

                $unit->atoms[$key] = [
                    'key' => $key,
                    'canonical_start' => $unit->canonicalStart + $offset,
                    'canonical_end' => $unit->canonicalStart + $offset + $length,
                    'utf16_start' => $unit->utf16Start + $utf16Offset,
                    'utf16_end' => $unit->utf16Start + $utf16Offset + Chunker::utf16Length($piece),
                    'text' => $piece,
                ];
            }

            $offset += $length;
        }
    }

    /**
     * Split text into windows of at most unitMaxChars, breaking at
     * sentence boundaries (falling back to word boundaries, then to a
     * hard codepoint cut for pathological unbroken text). Returns
     * [codepoint offset, window] pairs whose concatenation equals the
     * input exactly.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function windows(string $text): array
    {
        if (mb_strlen($text) <= $this->unitMaxChars) {
            return [[0, $text]];
        }

        // Sentence pieces: split AFTER terminal punctuation runs
        // (keeping trailing whitespace with the preceding sentence so
        // offsets stay exact and lossless).
        $pieces = preg_split(
            '/(?<=[.!?…])(?=\s)|(?<=[.!?…]["»”\'])(?=\s)/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [$text];

        $windows = [];
        $current = '';
        $currentOffset = 0;
        $cursor = 0;

        foreach ($pieces as $piece) {
            foreach ($this->hardSplit($piece) as $fragment) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($fragment) > $this->unitMaxChars) {
                    $windows[] = [$currentOffset, $current];
                    $current = '';
                    $currentOffset = $cursor;
                }

                $current .= $fragment;
                $cursor += mb_strlen($fragment);
            }
        }

        if ($current !== '') {
            $windows[] = [$currentOffset, $current];
        }

        return $windows;
    }

    /**
     * A single sentence longer than the budget is cut at the last word
     * boundary before the limit (hard codepoint cut when no space
     * exists). Lossless: fragments concatenate to the input.
     *
     * @return list<string>
     */
    private function hardSplit(string $piece): array
    {
        if (mb_strlen($piece) <= $this->unitMaxChars) {
            return [$piece];
        }

        $fragments = [];

        while (mb_strlen($piece) > $this->unitMaxChars) {
            $head = mb_substr($piece, 0, $this->unitMaxChars);
            $lastSpace = mb_strrpos($head, ' ');

            if ($lastSpace !== false && $lastSpace > (int) ($this->unitMaxChars / 2)) {
                $head = mb_substr($head, 0, $lastSpace + 1);
            }

            $fragments[] = $head;
            $piece = mb_substr($piece, mb_strlen($head));
        }

        if ($piece !== '') {
            $fragments[] = $piece;
        }

        return $fragments;
    }
}
