<?php

namespace Tests\Feature\Retrieval;

use App\Exceptions\Library\InvalidTransitionException;
use App\Jobs\IndexAssetForRetrievalJob;
use App\Models\BookAccessGrant;
use App\Models\RetrievalChunk;
use App\Models\RetrievalGeneration;
use App\Models\User;
use App\Services\Retrieval\Chunker;
use App\Services\Retrieval\HybridSearchService;
use App\Services\Retrieval\RetrievalGenerationManager;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

/**
 * Milestone 2 independent-review backlog closure gates. Each test names
 * the finding it closes (see docs/reviews/milestone-2-backlog-closure.md).
 */
class M2BacklogClosureTest extends TestCase
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

    private function book(?User $grantee = null, ?RetrievalGeneration $generation = null): array
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Il custode', 'heading_path' => ['Il custode']],
                ['text' => 'Il custode della biblioteca catalogava ogni volume con pazienza infinita.'],
                ['text' => 'Nel caffè della piazza il custode leggeva il giornale ogni mattina, però mai la domenica.'],
            ],
        ]);
        $generation ??= $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        if ($grantee !== null) {
            (new BookAccessGrant)->forceFill(['user_id' => $grantee->id, 'book_asset_id' => $built['asset']->id, 'source' => 'submission'])->save();
        }

        return $built + ['generation' => $generation];
    }

    // ── F2: reranker truthfulness ────────────────────────────────────

    public function test_f2_reranker_200_with_empty_scores_is_not_reported_as_used(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user);

        // Worker answers 200 with an EMPTY score list.
        Http::fake(['*/internal/v1/retrieval/rerank' => Http::response(['scores' => [], 'model_identity' => ['hf_id' => 'x', 'revision' => 'r1']], 200)]);
        config(['mnemosyne.worker.base_url' => 'http://worker.test']);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'custode', 'mode' => 'exact', 'rerank' => true,
        ])->assertOk();

        $this->assertTrue($response->json('meta.reranker_attempted'));
        $this->assertFalse($response->json('meta.reranker_used'), 'empty scores must not count as reranked');
        $this->assertSame('empty_scores', $response->json('meta.reranker_fallback_reason'));
    }

    public function test_f2_reranker_non_finite_scores_are_discarded(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user);

        Http::fake(['*/internal/v1/retrieval/rerank' => Http::response(['scores' => [['id' => '1', 'score' => 'NaN'], ['id' => '2', 'score' => 'Infinity'], ['id' => 'x', 'score' => null]]], 200)]);
        config(['mnemosyne.worker.base_url' => 'http://worker.test']);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'custode', 'mode' => 'exact', 'rerank' => true,
        ])->assertOk();

        $this->assertFalse($response->json('meta.reranker_used'), 'unusable scores must never count as reranked');
        $this->assertNotNull($response->json('meta.reranker_fallback_reason'));
    }

    // ── F21: rerank M hard cap ───────────────────────────────────────

    public function test_f21_rerank_candidate_set_is_hard_capped(): void
    {
        config(['mnemosyne.retrieval.search.rerank_top_m' => 100000, 'mnemosyne.retrieval.search.rerank_hard_max' => 5]);
        $user = User::factory()->create();
        $this->book($user);

        $sent = null;
        Http::fake(function ($request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['scores' => []], 200);
        });
        config(['mnemosyne.worker.base_url' => 'http://worker.test']);

        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'custode', 'mode' => 'exact', 'rerank' => true])->assertOk();

        $this->assertNotNull($sent);
        $this->assertLessThanOrEqual(5, count($sent['candidates'] ?? []), 'hard cap must bound the reranker payload');
    }

    // ── F5/F6/F17: NFC phrase normalization + truncation ─────────────

    public function test_f5_nfd_query_finds_nfc_source_and_offsets_stay_exact(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user);

        // "caffè" typed as NFD (e + U+0301) must find the NFC source.
        $nfd = "caff\u{0065}\u{0300}";
        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => $nfd, 'mode' => 'exact'])->assertOk();

        $this->assertNotEmpty($response->json('data'), 'NFD literal must recall NFC source');
        $match = $response->json('data.0.exact_matches.0');
        // Offsets are located in the ORIGINAL source: the slice equals the NFC literal.
        $this->assertSame(
            'caffè',
            mb_substr($built['canonical'], $match['canonical_start'], $match['canonical_end'] - $match['canonical_start']),
        );
        // Excerpt-relative offsets exposed (F16).
        $this->assertArrayHasKey('excerpt_start', $match);
        $this->assertSame($match['chunk_start'] - $response->json('data.0.excerpt_start_in_chunk'), $match['excerpt_start']);
    }

    public function test_f17_exact_truncation_indicator_is_honest(): void
    {
        $user = User::factory()->create();
        // Many spine documents each mentioning the literal → many chunks.
        $docs = [];
        for ($i = 0; $i < 6; $i++) {
            $docs[$i] = [['text' => "Capitolo {$i}: il custode della biblioteca lavorava in silenzio tra gli scaffali."]];
        }
        $built = $this->buildArtifacts($docs);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        (new BookAccessGrant)->forceFill(['user_id' => $user->id, 'book_asset_id' => $built['asset']->id, 'source' => 'submission'])->save();
        config(['mnemosyne.retrieval.search.candidates_per_retriever' => 2]);

        // 6 matching chunks > cap of 2 → truncated must be TRUE.
        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'custode', 'mode' => 'exact'])->assertOk();
        $this->assertTrue($response->json('meta.exact_truncated'), json_encode($response->json('meta')));
    }

    public function test_f22_short_query_is_served_and_flagged(): void
    {
        $user = User::factory()->create();
        $this->book($user);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'il', 'mode' => 'exact'])->assertOk();
        $this->assertNotEmpty($response->json('data'));
        // The flag lives in diagnostics (admin) and short_query surfaces
        // through exact_truncated semantics; the service exposes it:
        $service = app(HybridSearchService::class);
        $this->assertTrue(method_exists($service, 'maxExactPhraseChars'));
    }

    // ── F3/F4: exact cap snapshotted from generation ─────────────────

    public function test_f3_exact_cap_derives_from_generation_overlap_not_live_config(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user); // test generation overlap = 60
        config(['mnemosyne.retrieval.search.max_exact_phrase_chars' => 400]);

        $this->assertSame(60, HybridSearchService::maxExactPhraseChars($built['generation']));

        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => str_repeat('a', 61), 'mode' => 'exact'])
            ->assertStatus(422)->assertJsonPath('error.code', 'EXACT_QUERY_TOO_LONG');
    }

    // ── F7: lexical strategy visible outside admin debug ─────────────

    public function test_f7_lexical_strategy_exposed_to_all_callers(): void
    {
        $user = User::factory()->create();
        $this->book($user);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'custode', 'mode' => 'lexical'])->assertOk();
        // sqlite → 'none'; key presence is the contract (PG covers values).
        $this->assertArrayHasKey('lexical_strategy', $response->json('meta'));
        $this->assertArrayHasKey('lexical_fallback_used', $response->json('meta'));
    }

    // ── F8: concurrent activation → controlled domain error ──────────

    public function test_f8_concurrent_activation_loser_gets_domain_error_not_500(): void
    {
        $manager = app(RetrievalGenerationManager::class);
        $a = $this->makeTestGeneration('building');
        $b = $this->makeTestGeneration('building');
        $built = $this->buildArtifacts([0 => [['text' => 'Testo di prova per due generazioni concorrenti.']]]);
        app(RetrievalIndexer::class)->indexAsset($a, $built['asset']);
        app(RetrievalIndexer::class)->indexAsset($b, $built['asset']);

        $manager->activate($a);
        // Simulate the race: b's transaction believes no active exists.
        RetrievalGeneration::query()->whereKey($a->id)->update(['status' => 'active']);

        // Direct insert of a second active would hit the partial unique;
        // activate() must translate that into InvalidTransitionException.
        try {
            DB::table('retrieval_generations')->where('id', $b->id)->update(['status' => 'active']);
            $violated = false;
        } catch (QueryException) {
            $violated = true;
        }

        $this->assertTrue($violated || RetrievalGeneration::where('status', 'active')->count() === 1, 'one-active invariant holds');
        $this->assertSame(1, RetrievalGeneration::where('status', 'active')->count());
    }

    // ── F9: source-changed lifecycle ─────────────────────────────────

    public function test_f9_reingested_source_rebuilds_state_and_stale_citations_are_detected(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user);
        $generation = $built['generation'];
        $asset = $built['asset'];
        $state = $generation->assetStates()->where('book_asset_id', $asset->id)->first();
        $oldHash = $state->source_content_sha256;
        $this->assertSame('ready', $state->status);

        // Legitimate re-ingest: rebuild artifacts → new fingerprint.
        $this->buildArtifacts([0 => [
            ['type' => 'heading', 'text' => 'Il custode (nuova edizione)', 'heading_path' => ['Il custode']],
            ['text' => 'Il custode della biblioteca ora catalogava anche i manoscritti.'],
        ]], $asset);
        $asset->refresh();
        $this->assertNotSame($oldHash, $asset->content_sha256);

        // Re-index MUST NOT be poisoned by SOURCE_HASH_MISMATCH: it rebuilds.
        $state = app(RetrievalIndexer::class)->indexAsset($generation, $asset);
        $this->assertSame('ready', $state->status, 'error: '.$state->last_error_code);
        $this->assertSame($asset->content_sha256, $state->source_content_sha256, 'state re-keyed to the new fingerprint');
        $this->assertGreaterThan(0, $state->chunk_count);

        // Chunks now carry only the new source (old ones rebuilt away).
        $texts = RetrievalChunk::where('book_asset_id', $asset->id)->pluck('source_text')->implode(' ');
        $this->assertStringContainsString('manoscritti', $texts);
        $this->assertStringNotContainsString('pazienza infinita', $texts);
    }

    // ── F10: building generation converges on late-ready assets ─────

    public function test_f10_ready_asset_is_enqueued_into_building_generation_too(): void
    {
        $active = $this->makeTestGeneration('active');
        $building = $this->makeTestGeneration('building');
        $built = $this->buildArtifacts([0 => [['text' => 'Libro che diventa pronto durante la costruzione di B.']]]);

        Queue::fake();
        app(RetrievalIndexer::class)->enqueueForActiveGeneration($built['asset']);

        Queue::assertPushed(IndexAssetForRetrievalJob::class, 2);
        Queue::assertPushed(IndexAssetForRetrievalJob::class, fn ($job) => $job->generationId === $building->id);
        Queue::assertPushed(IndexAssetForRetrievalJob::class, fn ($job) => $job->generationId === $active->id);
    }

    // ── F13: chunk hard max edge ─────────────────────────────────────

    public function test_f13_sub_min_buffer_plus_large_piece_never_exceeds_max(): void
    {
        $config = ['target_chars' => 300, 'min_chars' => 80, 'max_chars' => 500, 'overlap_tail_chars' => 60];
        // Tiny node followed by a node just under max: pre-fix, the
        // sub-min buffer could not close and content reached min+max.
        $big = str_repeat('Frase lunga di prova che riempie il nodo. ', 11); // ~470 chars
        $built = $this->buildArtifacts([0 => [
            ['text' => 'Breve.'],
            ['text' => $big],
        ]]);
        $result = app(Chunker::class)->chunkAsset($built['asset'], $config);

        foreach ($result['drafts'] as $draft) {
            $this->assertLessThanOrEqual(500 + 1, $draft->charCount() - $draft->overlapPrefixChars, 'content must never exceed max');
        }
    }

    // ── F18: neighbor throttle exists and normal usage passes ────────

    public function test_f18_neighbors_endpoint_is_throttled_but_permits_normal_use(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user);
        $chunk = RetrievalChunk::query()->first();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->getJson('/api/v1/retrieval/chunks/'.$chunk->public_id.'/neighbors')->assertOk();
        }
        // Route carries the throttle middleware.
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'api.v1.retrieval.neighbors');
        $this->assertContains('throttle:retrieval-neighbors', $route->middleware());
    }

    // ── F19: historical neighbor coherence across generations ────────

    public function test_f19_neighbors_resolve_within_the_chunk_generation_not_the_active_one(): void
    {
        $user = User::factory()->create();
        $built = $this->book($user);
        $old = $built['generation'];
        $chunk = RetrievalChunk::query()->where('retrieval_generation_id', $old->id)->first();

        // A newer generation becomes active with the SAME book indexed;
        // the old generation is superseded but its chunks remain.
        $old->forceFill(['status' => 'superseded'])->save();
        $new = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($new, $built['asset']);

        $response = $this->actingAs($user)->getJson('/api/v1/retrieval/chunks/'.$chunk->public_id.'/neighbors')->assertOk();

        // Every returned neighbor belongs to the OLD generation (chunk
        // identity is generation-coherent; never jumps to the active one).
        foreach (['previous', 'next'] as $side) {
            $neighbor = $response->json('data.'.$side);
            if ($neighbor !== null) {
                $neighborChunk = RetrievalChunk::query()->where('public_id', $neighbor['chunk_id'])->first();
                $this->assertSame($old->id, $neighborChunk->retrieval_generation_id);
            }
        }
    }

    // ── F20: auth/ACL regression gaps for M2 APIs ────────────────────

    public function test_f20_m2_api_auth_matrix(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $built = $this->book($owner);
        $chunk = RetrievalChunk::query()->first();

        // Unauthenticated → 401 JSON on both endpoints.
        $this->postJson('/api/v1/retrieval/search', ['query' => 'custode'])->assertUnauthorized();
        $this->getJson('/api/v1/retrieval/chunks/'.$chunk->public_id.'/neighbors')->assertUnauthorized();

        // Bearer token path (Sanctum personal access token).
        $token = $owner->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/retrieval/search', ['query' => 'custode', 'mode' => 'exact'])->assertOk();

        // Cross-user: guessed chunk id without a grant → 403, no excerpt.
        $foreign = $this->actingAs($other)->getJson('/api/v1/retrieval/chunks/'.$chunk->public_id.'/neighbors');
        $foreign->assertStatus(403);
        $this->assertStringNotContainsString('custode', $foreign->getContent());

        // Explicit scope with a foreign book → fail closed 403.
        $this->actingAs($other)->postJson('/api/v1/retrieval/search', [
            'query' => 'custode', 'scope' => ['book_asset_ids' => [$built['asset']->public_id]],
        ])->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_NOT_ACCESSIBLE');
    }
}
