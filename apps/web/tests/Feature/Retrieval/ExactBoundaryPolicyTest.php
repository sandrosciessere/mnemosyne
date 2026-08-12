<?php

namespace Tests\Feature\Retrieval;

use App\Models\BookAccessGrant;
use App\Models\User;
use App\Services\Retrieval\RetrievalIndexer;
use App\Services\Retrieval\Retrievers\ExactRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

/**
 * E1 regression: the exact-search boundary guarantee (a literal
 * straddling the chunk partition is intact in one chunk) only holds for
 * phrases whose pre-boundary portion fits the chunker overlap. The
 * accepted exact phrase length is therefore capped at the overlap:
 * requests above it are rejected (exact mode) or the exact component is
 * skipped with a diagnostic (hybrid mode) — never silently searched.
 */
class ExactBoundaryPolicyTest extends TestCase
{
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        // Test generation uses overlap_tail_chars = 60: align the policy.
        config(['mnemosyne.retrieval.search.max_exact_phrase_chars' => 60]);
        Queue::fake();
    }

    private function indexedStraddleFixture(): array
    {
        $built = $this->buildArtifacts([
            0 => [
                ['text' => str_repeat('Filler alpha sentence provides bulk. ', 7).'The hidden key sits here.'],
                ['text' => 'Continues the hidden key phrase immediately in the next node with more prose to follow along.'],
                ['text' => str_repeat('Trailing beta sentence provides more bulk for the second chunk to exist. ', 4)],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        $state = app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->assertSame('ready', $state->status);

        return $built + ['generation' => $generation];
    }

    public function test_boundary_straddling_phrase_within_limit_is_found(): void
    {
        $context = $this->indexedStraddleFixture();

        // 57 chars, crossing the partition (tail of node 1 + '\n' + head
        // of node 2) — within the 60-char guarantee.
        $phrase = "The hidden key sits here.\nContinues the hidden key phrase";
        $this->assertLessThanOrEqual(60, mb_strlen($phrase));

        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], $phrase, false, 10,
        );

        $this->assertNotEmpty($results, 'phrase within the overlap guarantee must be found across the boundary');
        $match = $results[0]['matches'][0];
        $this->assertSame(
            $phrase,
            mb_substr($context['canonical'], $match['canonical_start'], $match['canonical_end'] - $match['canonical_start']),
        );
    }

    public function test_exact_mode_rejects_phrases_beyond_the_guarantee(): void
    {
        $context = $this->indexedStraddleFixture();
        $user = User::factory()->create();
        (new BookAccessGrant)->forceFill([
            'user_id' => $user->id,
            'book_asset_id' => $context['asset']->id,
            'source' => 'submission',
        ])->save();

        $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => str_repeat('parola ', 12), // 84 chars > 60
            'mode' => 'exact',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'EXACT_QUERY_TOO_LONG');
    }

    public function test_hybrid_mode_skips_exact_component_with_diagnostic_for_long_queries(): void
    {
        $context = $this->indexedStraddleFixture();
        $user = User::factory()->create();
        (new BookAccessGrant)->forceFill([
            'user_id' => $user->id,
            'book_asset_id' => $context['asset']->id,
            'source' => 'submission',
        ])->save();

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => str_repeat('hidden key filler alpha sentence ', 4), // > 60 chars
            'mode' => 'hybrid',
            'rerank' => false,
        ]);

        $response->assertOk();
        $this->assertSame('phrase_too_long', $response->json('meta.exact_skipped_reason'));
    }

    public function test_normal_exact_query_is_unaffected(): void
    {
        $context = $this->indexedStraddleFixture();

        $results = app(ExactRetriever::class)->search(
            $context['generation'], [$context['asset']->id], 'hidden key', false, 10,
        );

        $this->assertNotEmpty($results);
        foreach ($results as $result) {
            foreach ($result['matches'] as $match) {
                $this->assertSame('hidden key', mb_strtolower($match['text']));
            }
        }
    }
}
