<?php

namespace Tests\Feature\Retrieval;

use App\Services\Retrieval\Chunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

class ChunkerTest extends TestCase
{
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        $this->config = [
            'target_chars' => 300,
            'min_chars' => 80,
            'max_chars' => 500,
            'overlap_tail_chars' => 60,
        ];
    }

    private function chunker(): Chunker
    {
        return app(Chunker::class);
    }

    private function paragraphs(int $count, string $prefix, int $length = 120): array
    {
        return array_map(function ($index) use ($prefix, $length) {
            $sentence = "{$prefix} paragraph {$index} narrates deterministic events. ";

            return ['text' => rtrim(mb_substr(str_repeat($sentence, 10), 0, $length))];
        }, range(1, $count));
    }

    public function test_chunking_is_deterministic(): void
    {
        $built = $this->buildArtifacts([
            0 => array_merge(
                [['type' => 'heading', 'text' => 'Chapter One', 'heading_path' => ['Chapter One']]],
                $this->paragraphs(8, 'Alpha'),
            ),
        ]);

        $first = $this->chunker()->chunkAsset($built['asset'], $this->config);
        $second = $this->chunker()->chunkAsset($built['asset'], $this->config);

        $this->assertNotEmpty($first['drafts']);
        $this->assertSame(count($first['drafts']), count($second['drafts']));
        $this->assertSame(
            array_map(fn ($draft) => $draft->contentSha256, $first['drafts']),
            array_map(fn ($draft) => $draft->contentSha256, $second['drafts']),
        );
        $this->assertEquals(
            array_map(fn ($draft) => $draft->spans, $first['drafts']),
            array_map(fn ($draft) => $draft->spans, $second['drafts']),
        );
    }

    public function test_every_chunk_equals_its_canonical_substring(): void
    {
        $built = $this->buildArtifacts([
            0 => array_merge(
                [['type' => 'heading', 'text' => 'Capitolo Primo', 'heading_path' => ['Capitolo Primo']]],
                $this->paragraphs(6, 'La biblioteca'),
            ),
            1 => $this->paragraphs(5, 'Il custode'),
        ]);

        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);

        foreach ($result['drafts'] as $draft) {
            $expected = mb_substr(
                $built['canonical'],
                $draft->canonicalStart,
                $draft->canonicalEnd - $draft->canonicalStart,
            );
            $this->assertSame($expected, $draft->sourceText, "chunk {$draft->ordinal} != canonical slice");
        }
    }

    public function test_every_span_round_trips_in_all_three_coordinate_systems(): void
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Unicode 🜁 Chapter ✒️', 'heading_path' => ['Unicode 🜁 Chapter ✒️']],
                ['text' => 'Emoji 🎭🎭 and accents àèìòù café — plus CJK 記憶の図書館 and astral 𝔘𝔫𝔦𝔠𝔬𝔡𝔢 text.'],
                ['text' => 'Seconda frase con «virgolette» e apostrofi d\'autore. Poi ancora testo.'],
                ['text' => 'Русский текст о памяти и библиотеке. Ελληνικά για τη μνήμη.'],
            ],
        ]);

        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);
        $checked = 0;

        foreach ($result['drafts'] as $draft) {
            foreach ($draft->spans as $span) {
                $fromCanonical = mb_substr($built['canonical'], $span['canonical_start'], $span['canonical_end'] - $span['canonical_start']);
                $fromChunk = mb_substr($draft->sourceText, $span['chunk_start'], $span['chunk_end'] - $span['chunk_start']);
                $fromUtf16 = $this->utf16Slice($built['canonical'], $span['utf16_start'], $span['utf16_end']);

                $this->assertSame($fromCanonical, $fromChunk);
                $this->assertSame($fromCanonical, $fromUtf16);
                $this->assertLessThan($span['canonical_end'], $span['canonical_start']);
                $this->assertLessThan($span['utf16_end'], $span['utf16_start']);
                $checked++;
            }
        }

        $this->assertGreaterThan(2, $checked);
    }

    public function test_spine_boundary_always_breaks_and_never_carries_overlap(): void
    {
        $built = $this->buildArtifacts([
            0 => $this->paragraphs(3, 'Chapter ending'),
            1 => $this->paragraphs(3, 'Next chapter'),
        ]);

        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);

        foreach ($result['drafts'] as $draft) {
            $spines = collect($draft->spans)->pluck('spine_index')->unique();
            $this->assertCount(1, $spines, 'a chunk must never span two spine documents');
        }

        $firstOfDoc1 = collect($result['drafts'])->first(fn ($draft) => $draft->spineIndex === 1);
        $this->assertSame(0, $firstOfDoc1->overlapPrefixChars, 'no overlap across chapter boundary');
    }

    public function test_oversized_node_splits_at_sentence_boundaries_losslessly(): void
    {
        $sentences = [];
        for ($index = 1; $index <= 12; $index++) {
            $sentences[] = "Sentence number {$index} of the very long monolithic paragraph continues onward.";
        }
        $longText = implode(' ', $sentences); // ~900 chars > max 500

        $built = $this->buildArtifacts([0 => [['text' => $longText]]]);
        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);

        $this->assertSame(1, $result['counters']['nodes_split']);

        $allSpans = collect($result['drafts'])->flatMap(fn ($draft) => $draft->spans)
            ->filter(fn ($span) => $span['chunk_start'] >= ($span['is_overlap'] ?? 0)); // all
        $nodeIds = $allSpans->pluck('source_node_id')->unique();
        $this->assertCount(1, $nodeIds);

        // Reassemble the node from its NON-overlap span slices (spans in
        // the overlap prefix repeat text already contributed).
        $reassembled = '';
        foreach ($result['drafts'] as $draft) {
            foreach ($draft->spans as $span) {
                if ($span['chunk_start'] < $draft->overlapPrefixChars) {
                    continue;
                }
                $reassembled .= mb_substr($draft->sourceText, $span['chunk_start'], $span['chunk_end'] - $span['chunk_start']);
            }
        }
        $this->assertSame($longText, $reassembled, 'split must be lossless');

        foreach ($result['drafts'] as $draft) {
            // Hard max bounds the source-content region; the provenance-
            // mapped overlap prefix (≤ overlap+1 chars) sits on top.
            $this->assertLessThanOrEqual(
                500 + 1,
                $draft->charCount() - $draft->overlapPrefixChars,
            );
            $this->assertLessThanOrEqual(60 + 1, $draft->overlapPrefixChars);
        }
    }

    public function test_overlap_makes_boundary_straddling_phrases_findable(): void
    {
        // Craft paragraphs so a distinctive phrase straddles the first
        // chunk close: the tail sentence of one chunk + head of the next.
        $built = $this->buildArtifacts([
            0 => [
                ['text' => str_repeat('Filler alpha sentence provides bulk. ', 7).'The hidden key sits here.'],
                ['text' => 'Continues the hidden key phrase immediately in the next node with more prose to follow along.'],
                ['text' => str_repeat('Trailing beta sentence provides more bulk for the second chunk to exist. ', 4)],
            ],
        ]);

        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);
        $this->assertGreaterThanOrEqual(2, count($result['drafts']));

        // The straddling phrase: end of node 1 + separator + start of node 2.
        $phrase = "The hidden key sits here.\nContinues the hidden key phrase";

        $found = collect($result['drafts'])
            ->contains(fn ($draft) => str_contains($draft->sourceText, $phrase));
        $this->assertTrue($found, 'phrase straddling the chunk partition must appear intact in one chunk');

        // Overlap regions are fully provenance-mapped and flagged.
        $withOverlap = collect($result['drafts'])->first(fn ($draft) => $draft->overlapPrefixChars > 0);
        $this->assertNotNull($withOverlap);
        $overlapSpans = collect($withOverlap->spans)
            ->filter(fn ($span) => $span['chunk_end'] <= $withOverlap->overlapPrefixChars);
        $this->assertGreaterThan(0, $overlapSpans->count());
    }

    public function test_heading_starts_new_chunk_and_headings_carry_context(): void
    {
        $built = $this->buildArtifacts([
            0 => array_merge(
                [['type' => 'heading', 'text' => 'Part One', 'heading_path' => ['Part One']]],
                $this->paragraphs(3, 'Body one'),
                [['type' => 'heading', 'text' => 'Part Two', 'heading_path' => ['Part Two']]],
                $this->paragraphs(3, 'Body two'),
            ),
        ]);

        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);

        $partTwoChunk = collect($result['drafts'])->first(
            fn ($draft) => str_contains($draft->sourceText, 'Part Two'),
        );
        $this->assertNotNull($partTwoChunk);
        // The heading opens its chunk (not glued mid-chunk to Part One).
        $headingSpan = collect($partTwoChunk->spans)->firstWhere('node_type', 'heading');
        $this->assertSame($partTwoChunk->overlapPrefixChars, $headingSpan['chunk_start']);
        $this->assertSame(['Part Two'], $partTwoChunk->headingPath);
    }

    public function test_non_corpus_nodes_are_skipped_with_counters(): void
    {
        $built = $this->buildArtifacts([
            0 => [
                ['text' => 'Real text node number one with content.'],
                ['type' => 'figure', 'text' => '', 'corpus' => false],
                ['text' => 'Real text node number two with content.'],
            ],
        ]);

        $result = $this->chunker()->chunkAsset($built['asset'], $this->config);

        $this->assertSame(3, $result['counters']['nodes_total']);
        $this->assertSame(1, $result['counters']['nodes_skipped_no_text']);
        $texts = collect($result['drafts'])->pluck('sourceText')->implode(' ');
        $this->assertStringContainsString('node number one', $texts);
        $this->assertStringContainsString('node number two', $texts);
    }
}
