<?php

namespace Tests\Feature\Retrieval;

use App\Models\BookAccessGrant;
use App\Models\RetrievalChunk;
use App\Models\User;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

class RetrievalApiTest extends TestCase
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

    private function indexedBook(?User $grantee = null): array
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Il Custode', 'heading_path' => ['Il Custode']],
                ['text' => 'Il custode della biblioteca catalogava ogni volume con pazienza infinita.'],
                ['text' => 'La frase segreta di Mnemosyne appare esattamente una volta in questo libro.'],
                ['text' => 'Un ultimo paragrafo chiude il capitolo con serenità.'],
            ],
        ]);

        $generation = $this->makeTestGeneration('active');

        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        if ($grantee !== null) {
            (new BookAccessGrant)->forceFill([
                'user_id' => $grantee->id,
                'book_asset_id' => $built['asset']->id,
                'source' => 'submission',
            ])->save();
        }

        return ['asset' => $built['asset'], 'generation' => $generation, 'canonical' => $built['canonical']];
    }

    public function test_search_requires_authentication_and_active_generation(): void
    {
        $this->postJson('/api/v1/retrieval/search', ['query' => 'x'])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson('/api/v1/retrieval/search', ['query' => 'anything'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'NO_ACTIVE_GENERATION');
    }

    public function test_validation_rejects_pathological_input(): void
    {
        $user = User::factory()->create();
        $this->indexedBook($user);

        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => ''])
            ->assertStatus(422);
        // TrimStrings middleware reduces whitespace-only to empty → 422
        // via validation (the controller QUERY_EMPTY guard is defense in
        // depth for non-HTTP callers).
        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => '   '])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => str_repeat('q', 5000)])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'ok', 'top_k' => 0])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'ok', 'top_k' => 9999])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/retrieval/search', ['query' => 'ok', 'mode' => 'telepathy'])
            ->assertStatus(422);
    }

    public function test_exact_search_returns_provenanced_evidence(): void
    {
        $user = User::factory()->create();
        $context = $this->indexedBook($user);

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => false,
        ]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $hit = $data[0];
        $this->assertSame(1, $hit['rank']);
        $this->assertSame($context['asset']->public_id, $hit['book_asset_id']);
        $this->assertStringContainsString('frase segreta di Mnemosyne', $hit['excerpt']);
        $this->assertNotEmpty($hit['evidence_spans']);
        $this->assertNotEmpty($hit['exact_matches']);
        $this->assertSame('frase segreta di Mnemosyne', $hit['exact_matches'][0]['text']);

        // Programmatic provenance proof: canonical offsets → source text.
        $match = $hit['exact_matches'][0];
        $fromCanonical = mb_substr(
            $context['canonical'],
            $match['canonical_start'],
            $match['canonical_end'] - $match['canonical_start'],
        );
        $this->assertSame('frase segreta di Mnemosyne', $fromCanonical);

        // Span integrity in the API representation.
        foreach ($hit['evidence_spans'] as $span) {
            $this->assertLessThan($span['canonical_end'], $span['canonical_start']);
            $this->assertLessThan($span['utf16_end'], $span['utf16_start']);
            $this->assertNotEmpty($span['source_hash']);
            $this->assertNotEmpty($span['href']);
        }

        // No internal scores for non-admin users.
        $this->assertArrayNotHasKey('scores', $hit);
    }

    public function test_acl_users_cannot_search_or_scope_ungranted_books(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $context = $this->indexedBook($owner);

        // Unscoped search by a user with no grants: empty result set.
        $empty = $this->actingAs($stranger)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => false,
        ]);
        $empty->assertOk();
        $this->assertSame([], $empty->json('data'));

        // Possessing the ULID must not bypass ACL.
        $this->actingAs($stranger)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta',
            'mode' => 'exact',
            'scope' => ['book_asset_ids' => [$context['asset']->public_id]],
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'SCOPE_NOT_ACCESSIBLE');

        // Unknown ids are indistinguishable from unauthorized ones.
        $this->actingAs($stranger)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta',
            'scope' => ['book_asset_ids' => ['01aaaaaaaaaaaaaaaaaaaaaaaa']],
        ])->assertStatus(403);

        // Guessed chunk ULIDs leak nothing either.
        $chunk = RetrievalChunk::query()->first();
        $this->actingAs($stranger)
            ->getJson('/api/v1/retrieval/chunks/'.$chunk->public_id.'/neighbors')
            ->assertForbidden();

        // Admin sees everything.
        $admin = User::factory()->admin()->create();
        $adminHit = $this->actingAs($admin)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => false,
            'debug' => true,
        ]);
        $adminHit->assertOk();
        $this->assertNotEmpty($adminHit->json('data'));
        $this->assertArrayHasKey('scores', $adminHit->json('data.0'));
        $this->assertArrayHasKey('timings_ms', $adminHit->json('meta'));
    }

    public function test_granted_but_unindexed_assets_are_reported_not_silently_partial(): void
    {
        $user = User::factory()->create();
        $context = $this->indexedBook($user);

        // A second granted book that is NOT indexed in the generation.
        $other = $this->buildArtifacts([
            0 => [['text' => 'Un altro libro che non è ancora stato indicizzato per il retrieval.']],
        ]);
        (new BookAccessGrant)->forceFill([
            'user_id' => $user->id,
            'book_asset_id' => $other['asset']->id,
            'source' => 'submission',
        ])->save();

        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => false,
        ]);

        $response->assertOk();
        $this->assertSame([$other['asset']->public_id], $response->json('meta.skipped_assets'));
    }

    public function test_reranker_failure_degrades_honestly_to_fused_order(): void
    {
        $user = User::factory()->create();
        $this->indexedBook($user);

        // The worker is unreachable in this suite: requesting rerank must
        // fall back to the fused ranking WITH an explicit diagnostic —
        // never a silent pretend-success.
        $response = $this->actingAs($user)->postJson('/api/v1/retrieval/search', [
            'query' => 'frase segreta di Mnemosyne',
            'mode' => 'exact',
            'rerank' => true,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertFalse($response->json('meta.reranker_used'));
        $this->assertContains(
            $response->json('meta.reranker_fallback_reason'),
            ['worker_unavailable', 'reranker_error'],
        );
    }

    public function test_neighbors_returns_adjacent_context_for_authorized_users(): void
    {
        $user = User::factory()->create();
        $this->indexedBook($user);

        $chunk = RetrievalChunk::query()->orderBy('ordinal')->first();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/retrieval/chunks/'.$chunk->public_id.'/neighbors');

        $response->assertOk();
        $this->assertNull($response->json('data.previous'));
    }
}
