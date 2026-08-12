<?php

namespace Tests\Feature\Retrieval;

use App\Models\BookAccessGrant;
use App\Models\User;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

/**
 * E4 regression: reranking is opt-in (default false), runs under its
 * dedicated timeout, and every failure mode degrades honestly to the
 * fused order with a specific fallback reason.
 */
class RerankerPolicyTest extends TestCase
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

    private function indexedBookFor(User $user): void
    {
        $built = $this->buildArtifacts([
            0 => [
                ['text' => 'La frase segreta di Mnemosyne appare esattamente una volta in questo libro di prova.'],
                ['text' => 'Un secondo paragrafo con parole diverse per dare più candidati alla ricerca della frase.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        (new BookAccessGrant)->forceFill([
            'user_id' => $user->id,
            'book_asset_id' => $built['asset']->id,
            'source' => 'submission',
        ])->save();
    }

    public function test_omitted_rerank_flag_never_contacts_the_reranker(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $this->indexedBookFor($user);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertFalse($response->json('meta.reranker_used'));
        $this->assertNull($response->json('meta.reranker_fallback_reason'));
        Http::assertNothingSent();
    }

    public function test_explicit_rerank_true_invokes_the_reranker(): void
    {
        $user = User::factory()->create();
        $this->indexedBookFor($user);

        Http::fake(function ($request) {
            $this->assertStringContainsString('/internal/v1/retrieval/rerank', $request->url());
            $scores = array_map(
                fn ($candidate) => ['id' => $candidate['id'], 'score' => 1.0],
                $request['candidates'],
            );

            return Http::response([
                'model_key' => 'mmarco-mini-v1',
                'model_identity' => ['hf_id' => 'x', 'revision' => 'y'],
                'scores' => $scores,
            ]);
        });

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('meta.reranker_used'));
        Http::assertSentCount(1);
    }

    public function test_reranker_timeout_returns_fused_order_with_timeout_reason(): void
    {
        $user = User::factory()->create();
        $this->indexedBookFor($user);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => true,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'), 'fused results must survive a reranker timeout');
        $this->assertFalse($response->json('meta.reranker_used'));
        $this->assertSame('timeout', $response->json('meta.reranker_fallback_reason'));
    }

    public function test_reranker_worker_error_keeps_existing_honest_fallback(): void
    {
        $user = User::factory()->create();
        $this->indexedBookFor($user);

        Http::fake(['*' => Http::response(['detail' => ['code' => 'INTERNAL_ERROR']], 500)]);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => true,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertFalse($response->json('meta.reranker_used'));
        $this->assertSame('worker_unavailable', $response->json('meta.reranker_fallback_reason'));
    }
}
