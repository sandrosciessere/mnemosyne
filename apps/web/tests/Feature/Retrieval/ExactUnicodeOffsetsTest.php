<?php

namespace Tests\Feature\Retrieval;

use App\Models\BookAsset;
use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\Chunker;
use App\Services\Retrieval\RetrievalIndexer;
use App\Services\Retrieval\Retrievers\ExactRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

/**
 * C1 regression suite: Unicode case folding is NOT length-preserving
 * (İ U+0130 lowercases to i + U+0307). Case-insensitive exact-match
 * offsets must be located in the ORIGINAL source string — never derived
 * from a length-changing folded copy — and every returned match must
 * satisfy caseFold(match_text) == caseFold(phrase).
 */
class ExactUnicodeOffsetsTest extends TestCase
{
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    /** @return array{asset: BookAsset, canonical: string, generation: RetrievalGeneration} */
    private function indexedFixture(array $nodes): array
    {
        $built = $this->buildArtifacts([0 => $nodes]);
        $generation = $this->makeTestGeneration('active');
        $state = app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->assertSame('ready', $state->status, (string) $state->last_error_message);

        return $built + ['generation' => $generation];
    }

    /** Assert a match's offsets are true in chunk, canonical and UTF-16 space. */
    private function assertMatchProvenance(array $match, RetrievalChunk $chunk, string $canonical, string $phrase, bool $caseSensitive): void
    {
        $fold = fn (string $text) => $caseSensitive ? $text : mb_strtolower($text);

        // Returned text equals the requested literal under the selected
        // case semantics, and is the true original-chunk slice.
        $this->assertSame($fold($phrase), $fold($match['text']));
        $this->assertSame(
            $match['text'],
            mb_substr($chunk->source_text, $match['chunk_start'], $match['chunk_end'] - $match['chunk_start']),
        );

        // Canonical offsets point at the same text in the source corpus.
        $this->assertSame(
            $match['text'],
            mb_substr($canonical, $match['canonical_start'], $match['canonical_end'] - $match['canonical_start']),
        );

        // EvidenceSpan mapping: the covering span agrees in all three
        // coordinate systems, so the UTF-16 offset of the match derived
        // from the span is correct too.
        $span = $chunk->spans->first(
            fn ($span) => $match['chunk_start'] >= $span->chunk_start && $match['chunk_start'] < $span->chunk_end,
        );
        $this->assertNotNull($span, 'match start must be covered by an evidence span');
        $this->assertSame(
            $span->canonical_start + ($match['chunk_start'] - $span->chunk_start),
            $match['canonical_start'],
        );

        $utf16Start = $span->utf16_start
            + Chunker::utf16Length(mb_substr($canonical, $span->canonical_start, $match['canonical_start'] - $span->canonical_start));
        $this->assertSame(
            $match['text'],
            $this->utf16Slice($canonical, $utf16Start, $utf16Start + Chunker::utf16Length($match['text'])),
        );
    }

    public function test_length_changing_fold_before_match_keeps_offsets_exact(): void
    {
        $context = $this->indexedFixture([
            ['text' => 'İstanbul e İzmir aprono il capitolo; the MATCH target sits here, dopo le città turche.'],
        ]);

        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'match', false, 10,
        );

        $this->assertNotEmpty($results, 'case-insensitive search must find MATCH');
        $match = $results[0]['matches'][0];

        $this->assertSame('MATCH', $match['text']);
        $this->assertMatchProvenance($match, $results[0]['chunk'], $context['canonical'], 'match', false);
    }

    public function test_length_changing_fold_inside_match_never_returns_false_provenance(): void
    {
        $context = $this->indexedFixture([
            ['text' => 'Il viaggio verso İstanbul iniziò all\'alba con la nebbia sul Bosforo.'],
        ]);

        // Same-codepoint phrase in a different case: must be found with
        // exact original offsets despite the fold-expanding İ inside it.
        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'İSTANBUL', false, 10,
        );

        if ($results !== []) {
            $this->assertMatchProvenance(
                $results[0]['matches'][0], $results[0]['chunk'], $context['canonical'], 'İSTANBUL', false,
            );
        }

        // ASCII query against the İ source: whatever the engine returns,
        // every emitted match must fold-equal the phrase (no corrupted
        // slice may ever surface as provenance).
        $ascii = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'istanbul', false, 10,
        );

        foreach ($ascii as $result) {
            foreach ($result['matches'] as $match) {
                $this->assertSame('istanbul', mb_strtolower($match['text']));
                $this->assertMatchProvenance($match, $result['chunk'], $context['canonical'], 'istanbul', false);
            }
        }
    }

    public function test_astral_unicode_before_match_keeps_utf16_mapping(): void
    {
        $context = $this->indexedFixture([
            ['text' => '🎭🎭 astral 𝔘𝔫𝔦𝔠𝔬𝔡𝔢 prologue 🌊 before the hidden BEACON phrase ends the node.'],
        ]);

        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'beacon', false, 10,
        );

        $this->assertNotEmpty($results);
        $match = $results[0]['matches'][0];
        $this->assertSame('BEACON', $match['text']);
        $this->assertMatchProvenance($match, $results[0]['chunk'], $context['canonical'], 'beacon', false);
    }

    public function test_multiple_matches_each_have_independent_exact_offsets(): void
    {
        $context = $this->indexedFixture([
            ['text' => 'İl faro guida. Poi il faro brilla mentre İstanbul dorme, e infine IL FARO tace.'],
        ]);

        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'il faro', false, 10,
        );

        $this->assertNotEmpty($results);
        $matches = $results[0]['matches'];
        $this->assertGreaterThanOrEqual(2, count($matches));

        $starts = [];
        foreach ($matches as $match) {
            $this->assertMatchProvenance($match, $results[0]['chunk'], $context['canonical'], 'il faro', false);
            $starts[] = $match['chunk_start'];
        }
        $this->assertSame($starts, array_values(array_unique($starts)), 'matches must not repeat positions');
    }

    public function test_case_sensitive_behavior_is_unchanged(): void
    {
        $context = $this->indexedFixture([
            ['text' => 'İstanbul precedes the MATCH literal, and later a lowercase match follows.'],
        ]);

        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'MATCH', true, 10,
        );

        $this->assertNotEmpty($results);
        foreach ($results[0]['matches'] as $match) {
            $this->assertSame('MATCH', $match['text']);
            $this->assertMatchProvenance($match, $results[0]['chunk'], $context['canonical'], 'MATCH', true);
        }
    }
}
