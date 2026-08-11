<?php

namespace App\Services\Retrieval;

use App\Models\BookAsset;
use App\Services\Library\LibraryStorage;

/**
 * Deterministic structural chunker (version in config: retrieval.chunker).
 *
 * Input: the Milestone 1 spine JSONL artifacts (authoritative source
 * structure). Only fingerprint-corpus nodes (normalized_start != null)
 * contribute text, so every chunk's source_text is EXACTLY the canonical
 * substring canonical[first_span.start … last_span.end] — adjacent corpus
 * nodes are separated by the canonical '\n', same-node split pieces are
 * contiguous.
 *
 * Rules (deterministic; no timestamps/randomness may influence output):
 *  - a spine-document boundary ALWAYS closes the current chunk (chapter
 *    endings are never merged into the next chapter);
 *  - a heading node closes the current chunk when it already holds at
 *    least min_chars;
 *  - a chunk closes when it reaches target_chars;
 *  - nodes larger than max_chars are split at sentence boundaries into
 *    pieces (spans then reference sub-ranges of the same source node);
 *  - overlap: the last whole pieces of a chunk (up to overlap_tail_chars)
 *    are repeated at the start of the next chunk WITHIN the same spine
 *    document, as fully provenance-mapped spans (overlap_prefix_chars
 *    records the repeated region; final selection dedupes by span
 *    overlap). This guarantees literal phrases that straddle the chunk
 *    partition boundary appear intact in at least one chunk.
 *  - image-only / non-corpus nodes are skipped and counted; no text is
 *    ever invented.
 */
class Chunker
{
    public function __construct(private readonly LibraryStorage $storage) {}

    /** @return array{drafts: list<ChunkDraft>, counters: array<string, int>} */
    public function chunkAsset(BookAsset $asset, array $config): array
    {
        $target = (int) $config['target_chars'];
        $min = (int) $config['min_chars'];
        $max = (int) $config['max_chars'];
        $overlap = (int) $config['overlap_tail_chars'];

        $counters = ['nodes_total' => 0, 'nodes_skipped_no_text' => 0, 'nodes_split' => 0, 'pieces' => 0];

        $drafts = [];
        $buffer = [];           // list of piece arrays for the current chunk
        $bufferLen = 0;         // mb length incl. separators
        $bufferOverlapLen = 0;  // portion of bufferLen that is overlap prefix
        $overlapPieces = [];    // pieces repeated from the previous chunk
        $ordinal = 0;
        $currentSpine = null;

        $flush = function (bool $allowOverlapCarry) use (&$buffer, &$bufferLen, &$bufferOverlapLen, &$overlapPieces, &$drafts, &$ordinal, $overlap) {
            if ($buffer === []) {
                return;
            }

            $drafts[] = $this->buildDraft($ordinal++, $buffer);

            $overlapPieces = $allowOverlapCarry
                ? $this->tailPiecesForOverlap($buffer, $overlap)
                : [];

            $buffer = [];
            $bufferLen = 0;
            $bufferOverlapLen = 0;
        };

        foreach ($this->streamPieces($asset, $max, $counters) as $piece) {
            // Spine document boundary: hard break, never carry overlap
            // across chapters/documents (clear any pending overlap even
            // when the buffer already flushed at target size).
            if ($currentSpine !== null && $piece['spine_index'] !== $currentSpine) {
                $flush(false);
                $overlapPieces = [];
            }
            $currentSpine = $piece['spine_index'];

            // Heading starts a fresh chunk once the buffer is substantial.
            if ($piece['node_type'] === 'heading' && $bufferLen >= $min) {
                $flush(true);
            }

            // Hard maximum on SOURCE CONTENT (overlap prefix excluded):
            // close the chunk before this piece would push content past max.
            $contentLen = $bufferLen - $bufferOverlapLen;
            if ($buffer !== [] && $contentLen >= $min
                && $contentLen + 1 + mb_strlen($piece['text']) > $max) {
                $flush(true);
            }

            // Start-of-chunk: prepend pending overlap pieces (same doc only).
            if ($buffer === [] && $overlapPieces !== []) {
                foreach ($overlapPieces as $overlapPiece) {
                    $overlapPiece['is_overlap'] = true;
                    $buffer[] = $overlapPiece;
                    $bufferLen += ($bufferLen > 0 ? 1 : 0) + mb_strlen($overlapPiece['text']);
                }
                $bufferOverlapLen = $bufferLen;
                $overlapPieces = [];
            }

            $buffer[] = $piece;
            $bufferLen += ($bufferLen > 0 ? 1 : 0) + mb_strlen($piece['text']);

            if ($bufferLen - $bufferOverlapLen >= $target) {
                $flush(true);
            }
        }

        $flush(false);

        return ['drafts' => $drafts, 'counters' => $counters];
    }

    /**
     * Streams provenance pieces in reading order. A piece is a whole
     * corpus node, or a sentence-boundary fragment of an oversized node.
     *
     * @return \Generator<array>
     */
    private function streamPieces(BookAsset $asset, int $max, array &$counters): \Generator
    {
        $dir = $asset->artifactDir($asset->pipeline_version ?? '1');
        $disk = $this->storage->disk();

        $files = collect($disk->files($dir.'/spine'))
            ->filter(fn ($file) => str_ends_with($file, '.jsonl'))
            ->sort()
            ->values();

        foreach ($files as $file) {
            $stream = $disk->readStream($file);

            if ($stream === null) {
                continue;
            }

            try {
                while (($line = fgets($stream)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $node = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    $counters['nodes_total']++;

                    if (($node['normalized_start'] ?? null) === null || $node['text'] === '') {
                        $counters['nodes_skipped_no_text']++;

                        continue;
                    }

                    yield from $this->piecesForNode($node, $max, $counters);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** @return \Generator<array> */
    private function piecesForNode(array $node, int $max, array &$counters): \Generator
    {
        $base = [
            'source_node_id' => $node['node_id'],
            'spine_index' => $node['spine_index'],
            'href' => $node['source']['href'] ?? '',
            'fragment' => $node['source']['fragment'] ?? null,
            'node_type' => $node['type'],
            'heading_path' => $node['heading_path'] ?? [],
            'source_hash' => $node['source_hash'],
            'is_overlap' => false,
        ];

        $text = $node['text'];

        if (mb_strlen($text) <= $max) {
            $counters['pieces']++;

            yield $base + [
                'text' => $text,
                'canonical_start' => $node['normalized_start'],
                'canonical_end' => $node['normalized_end'],
                'utf16_start' => $node['normalized_start_utf16'],
                'utf16_end' => $node['normalized_end_utf16'],
            ];

            return;
        }

        // Oversized node: deterministic sentence-boundary split.
        $counters['nodes_split']++;
        $offsetChars = 0;
        $offsetUtf16 = 0;

        foreach ($this->splitSentences($text, $max) as $fragmentText) {
            $fragmentChars = mb_strlen($fragmentText);
            $fragmentUtf16 = self::utf16Length($fragmentText);
            $counters['pieces']++;

            yield $base + [
                'text' => $fragmentText,
                'canonical_start' => $node['normalized_start'] + $offsetChars,
                'canonical_end' => $node['normalized_start'] + $offsetChars + $fragmentChars,
                'utf16_start' => $node['normalized_start_utf16'] + $offsetUtf16,
                'utf16_end' => $node['normalized_start_utf16'] + $offsetUtf16 + $fragmentUtf16,
            ];

            $offsetChars += $fragmentChars;
            $offsetUtf16 += $fragmentUtf16;
        }
    }

    /**
     * Splits text into fragments of at most $max chars, cutting at
     * sentence ends where possible and NEVER losing characters: the
     * concatenation of fragments equals the input exactly.
     *
     * @return list<string>
     */
    private function splitSentences(string $text, int $max): array
    {
        // Sentence units keep their trailing terminator + whitespace so
        // concatenation is lossless.
        $units = preg_split('/(?<=[.!?…;:])\s+(?=\p{Lu}|\p{N}|["«“‘\'])/u', $text) ?: [$text];

        // Restore the whitespace consumed by the split: recompute by
        // walking the original text.
        $restored = [];
        $cursor = 0;
        foreach ($units as $index => $unit) {
            $position = mb_strpos($text, $unit, $cursor);
            $position = $position === false ? $cursor : $position;
            if ($index > 0) {
                // Attach any gap (whitespace) to the PREVIOUS unit.
                $gap = mb_substr($text, $cursor, $position - $cursor);
                $restored[count($restored) - 1] .= $gap;
            }
            $restored[] = $unit;
            $cursor = $position + mb_strlen($unit);
        }
        if ($cursor < mb_strlen($text)) {
            $restored[count($restored) - 1] .= mb_substr($text, $cursor);
        }

        $fragments = [];
        $current = '';

        foreach ($restored as $unit) {
            // A single unit longer than max: hard-split it (rare).
            while (mb_strlen($unit) > $max) {
                if ($current !== '') {
                    $fragments[] = $current;
                    $current = '';
                }
                $fragments[] = mb_substr($unit, 0, $max);
                $unit = mb_substr($unit, $max);
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($unit) > $max) {
                $fragments[] = $current;
                $current = '';
            }

            $current .= $unit;
        }

        if ($current !== '') {
            $fragments[] = $current;
        }

        return $fragments;
    }

    /**
     * Overlap tail of a closing chunk: last whole pieces fitting the
     * budget, then — for the remaining budget — a trailing-sentence
     * sub-piece of the preceding piece (same node, adjusted offsets), so
     * overlap almost always exists and stays fully provenance-mapped.
     */
    private function tailPiecesForOverlap(array $buffer, int $overlap): array
    {
        if ($overlap <= 0) {
            return [];
        }

        $tail = [];
        $total = 0;
        $index = count($buffer) - 1;

        for (; $index >= 0; $index--) {
            $piece = $buffer[$index];
            $length = mb_strlen($piece['text']);

            if ($total + $length > $overlap) {
                break;
            }

            $piece['is_overlap'] = false; // reset; flagged again on prepend
            array_unshift($tail, $piece);
            $total += $length + 1;
        }

        // Partial tail of the next-older piece, at sentence granularity
        // (word-aligned raw tail as deterministic fallback).
        $remaining = $overlap - $total;

        if ($index >= 0 && $remaining >= 20) {
            $piece = $buffer[$index];
            $tailText = $this->trailingSentences($piece['text'], $remaining);

            if ($tailText !== '') {
                $prefix = mb_substr($piece['text'], 0, mb_strlen($piece['text']) - mb_strlen($tailText));
                $sub = $piece;
                $sub['text'] = $tailText;
                $sub['canonical_start'] = $piece['canonical_start'] + mb_strlen($prefix);
                $sub['utf16_start'] = $piece['utf16_start'] + self::utf16Length($prefix);
                $sub['is_overlap'] = false;
                array_unshift($tail, $sub);
            }
        }

        return $tail;
    }

    /** Trailing whole sentences of $text fitting $budget chars; else a
     *  word-aligned raw tail; '' when nothing sensible fits. */
    private function trailingSentences(string $text, int $budget): string
    {
        $units = preg_split('/(?<=[.!?…;:])\s+(?=\p{Lu}|\p{N}|["«“‘\'])/u', $text) ?: [$text];

        $tail = '';
        for ($index = count($units) - 1; $index >= 0; $index--) {
            $candidate = $units[$index].($tail === '' ? '' : ' '.$tail);
            if (mb_strlen($candidate) > $budget) {
                break;
            }
            $tail = $candidate;
        }

        if ($tail !== '' && str_ends_with($text, $tail)) {
            return $tail;
        }

        // Word-aligned raw tail fallback.
        $raw = mb_substr($text, -$budget);
        $space = mb_strpos($raw, ' ');

        if ($space === false || $space >= $budget - 20) {
            return '';
        }

        return mb_substr($raw, $space + 1);
    }

    private function buildDraft(int $ordinal, array $pieces): ChunkDraft
    {
        $text = '';
        $spans = [];
        $spanOrdinal = 0;
        $previous = null;
        $overlapPrefixChars = 0;
        $inOverlapPrefix = true;

        foreach ($pieces as $piece) {
            if ($text !== '') {
                // Same-node continuation pieces are contiguous in the
                // canonical corpus; distinct nodes are separated by '\n'.
                $contiguous = $previous !== null
                    && $piece['canonical_start'] === $previous['canonical_end'];

                if (! $contiguous) {
                    $text .= "\n";
                }
            }

            $chunkStart = mb_strlen($text);
            $text .= $piece['text'];
            $chunkEnd = mb_strlen($text);

            // Overlap prefix region = everything before the first
            // NON-overlap span (separators included).
            if ($inOverlapPrefix && ! ($piece['is_overlap'] ?? false)) {
                $overlapPrefixChars = $chunkStart;
                $inOverlapPrefix = false;
            }

            $spans[] = [
                'span_ordinal' => $spanOrdinal++,
                'source_node_id' => $piece['source_node_id'],
                'spine_index' => $piece['spine_index'],
                'href' => $piece['href'],
                'fragment' => $piece['fragment'],
                'node_type' => $piece['node_type'],
                'heading_path' => $piece['heading_path'],
                'canonical_start' => $piece['canonical_start'],
                'canonical_end' => $piece['canonical_end'],
                'utf16_start' => $piece['utf16_start'],
                'utf16_end' => $piece['utf16_end'],
                'chunk_start' => $chunkStart,
                'chunk_end' => $chunkEnd,
                'source_hash' => $piece['source_hash'],
            ];

            $previous = $piece;
        }

        // Content+provenance fingerprint: identical source structure and
        // config always produce identical hashes; ULIDs never carry
        // content identity.
        $provenance = implode("\n", array_map(
            fn ($span) => $span['source_node_id'].':'.$span['canonical_start'].'-'.$span['canonical_end'],
            $spans,
        ));
        $hash = hash('sha256', $provenance."\0".$text);

        $firstContent = collect($spans)->first(fn ($span, $index) => $spans[$index]['chunk_start'] >= $overlapPrefixChars) ?? $spans[0];

        return new ChunkDraft(
            ordinal: $ordinal,
            spineIndex: $pieces[0]['spine_index'],
            headingPath: $firstContent['heading_path'],
            sourceText: $text,
            spans: $spans,
            canonicalStart: $spans[0]['canonical_start'],
            canonicalEnd: $spans[count($spans) - 1]['canonical_end'],
            overlapPrefixChars: $overlapPrefixChars,
            contentSha256: $hash,
        );
    }

    /** UTF-16 code units of a UTF-8 string (matches worker semantics). */
    public static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
