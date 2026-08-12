<?php

namespace Tests\Feature\Answers;

use App\Models\GroundedAnswerEvidence;
use App\Models\RetrievalChunk;
use App\Models\User;
use App\Services\Answers\AnswerPresenter;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Reader\ReaderResolver;
use App\Services\Retrieval\Chunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class ReaderAndStaleSourceTest extends TestCase
{
    use BuildsAnswerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    /** Runs a full fake-provider answer over the unicode corpus. */
    private function unicodeAnswer(User $user): array
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $built = $this->unicodeBook($user);

        $run = $this->makeRun($user, 'liutaio', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il liutaio costruì un violino perfetto.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2']));
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        return ['run' => $run, 'built' => $built];
    }

    public function test_reader_highlight_offsets_are_exact_for_unicode_text(): void
    {
        $user = User::factory()->create();
        ['run' => $run, 'built' => $built] = $this->unicodeAnswer($user);

        $evidence = $run->evidence->firstWhere('citation_number', 1);
        $this->assertNotNull($evidence);

        $resolver = app(ReaderResolver::class);
        $resolved = $resolver->resolveEvidence($evidence);
        $this->assertSame('ok', $resolved['status']);

        // Slice the NODE text (as the browser will) with the
        // node-relative UTF-16 offsets: it must equal the persisted
        // exact excerpt even with 𝄞/emoji/accents earlier in the text.
        $nodes = $resolver->section($built['asset'], $resolved['spine_index']);
        $node = collect($nodes)->firstWhere('id', $resolved['node_id']);
        $this->assertNotNull($node);

        $sliced = $this->utf16Slice($node['text'], $resolved['utf16_start'], $resolved['utf16_end']);
        $this->assertSame($evidence->excerpt, $sliced);
        $this->assertStringContainsString('𝄞', $node['text'], 'astral char must precede the highlight');
    }

    public function test_reader_page_authorizes_and_renders_highlights(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        ['run' => $run, 'built' => $built] = $this->unicodeAnswer($user);
        $assetId = $built['asset']->public_id;

        // Owner opens the citation deep link.
        $response = $this->actingAs($user)->get(
            "/library/books/{$assetId}/reader?answer={$run->public_id}&evidence=E2",
        );
        $response->assertOk();
        $page = $response->viewData('page')['props'];
        $this->assertNotEmpty($page['highlights']);
        $this->assertSame('E2', $page['highlights'][0]['evidence_key']);
        $this->assertSame([], $page['stale_notices']);
        $this->assertSame($run->public_id, $page['answer_id']);

        // No grant on the book → reader route is closed.
        $this->actingAs($other)->get("/library/books/{$assetId}/reader")->assertForbidden();

        // Grant on the book but NOT on the answer → the answer deep link
        // still fails closed.
        $this->grant($built['asset'], $other);
        $this->actingAs($other)->get(
            "/library/books/{$assetId}/reader?answer={$run->public_id}&evidence=E2",
        )->assertForbidden();

        // Anonymous → login redirect.
        $this->post('/logout');
        $this->get("/library/books/{$assetId}/reader")->assertRedirect();
    }

    public function test_multi_evidence_highlighting_across_nodes(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'lanterna', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'La lanterna era il centro della vita del faro.', ['E2', 'E3']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'strong', ['E2', 'E3'], 'MULTIPLE_PASSAGES'));
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $evidence = $run->evidence()->whereNotNull('citation_number')->get();
        $this->assertCount(2, $evidence);
        $this->assertNotSame(
            $evidence[0]->source_node_id,
            $evidence[1]->source_node_id,
            'the two cited units must come from different source nodes for this regression',
        );

        $keys = $evidence->pluck('evidence_key')->implode(',');
        $response = $this->actingAs($user)->get(
            '/library/books/'.$built['asset']->public_id."/reader?answer={$run->public_id}&evidence={$keys}",
        );
        $response->assertOk();
        $highlights = $response->viewData('page')['props']['highlights'];

        // Both ranges resolved and exact (same spine document here).
        $this->assertCount(2, $highlights);

        $resolver = app(ReaderResolver::class);
        foreach ($evidence as $item) {
            $resolved = $resolver->resolveEvidence($item);
            $this->assertSame('ok', $resolved['status']);
        }
    }

    public function test_stale_source_fails_closed_everywhere(): void
    {
        $user = User::factory()->create();
        ['run' => $run, 'built' => $built] = $this->unicodeAnswer($user);
        $evidence = $run->evidence->firstWhere('citation_number', 1);

        // Simulate re-ingestion: the asset fingerprint changes.
        $built['asset']->forceFill(['content_sha256' => str_repeat('0', 64)])->save();

        // 1. Reader resolution refuses to highlight.
        $resolved = app(ReaderResolver::class)->resolveEvidence($evidence->fresh());
        $this->assertSame('CITATION_SOURCE_CHANGED', $resolved['status']);
        $this->assertNull($resolved['utf16_start']);

        // 2. API presentation flags the citation as stale.
        $presented = app(AnswerPresenter::class)->present($run->fresh());
        $this->assertTrue($presented['citations'][0]['stale']);
        $this->assertSame('CITATION_SOURCE_CHANGED', $presented['citations'][0]['stale_reason']);

        // 3. Reader page shows the stale notice and no highlight.
        $response = $this->actingAs($user)->get(
            '/library/books/'.$built['asset']->public_id."/reader?answer={$run->public_id}&evidence=E2",
        );
        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame([], $props['highlights']);
        $this->assertSame('CITATION_SOURCE_CHANGED', $props['stale_notices'][0]['status']);
    }

    public function test_node_level_hash_mismatch_also_fails_closed(): void
    {
        $user = User::factory()->create();
        ['run' => $run, 'built' => $built] = $this->unicodeAnswer($user);
        $evidence = $run->evidence->firstWhere('citation_number', 1);

        // Asset fingerprint still matches, but the persisted node hash
        // does not (e.g. unitizer bug or corrupted artifact).
        GroundedAnswerEvidence::query()->whereKey($evidence->id)
            ->update(['source_hash' => str_repeat('f', 64)]);

        $resolved = app(ReaderResolver::class)->resolveEvidence($evidence->fresh());
        $this->assertSame('CITATION_SOURCE_CHANGED', $resolved['status']);
    }

    public function test_answer_survives_generation_supersession(): void
    {
        $user = User::factory()->create();
        ['run' => $run, 'built' => $built] = $this->unicodeAnswer($user);

        // A new generation becomes active; the old one is superseded and
        // even emptied — historical answers must not care.
        $old = $built['generation'];
        $old->forceFill(['status' => 'superseded'])->save();
        $this->makeTestGeneration('active');
        RetrievalChunk::query()->where('retrieval_generation_id', $old->id)->delete();

        $presented = app(AnswerPresenter::class)->present($run->fresh());
        $this->assertSame('answered', $presented['outcome']);
        $this->assertFalse($presented['citations'][0]['stale']);
        $this->assertNotSame('', $presented['citations'][0]['excerpt']);

        $resolved = app(ReaderResolver::class)->resolveEvidence(
            $run->evidence()->whereNotNull('citation_number')->first(),
        );
        $this->assertSame('ok', $resolved['status'], 'resolution goes through canonical artifacts, not chunks');

        // Audit metadata still names the ORIGINAL generation.
        $this->assertSame($old->id, $run->fresh()->retrieval_generation_id);
    }

    public function test_evidence_excerpt_utf16_invariant_against_canonical(): void
    {
        $user = User::factory()->create();
        ['run' => $run, 'built' => $built] = $this->unicodeAnswer($user);

        foreach ($run->evidence as $evidence) {
            $this->assertSame(
                $this->utf16Slice($built['canonical'], $evidence->utf16_start, $evidence->utf16_end),
                $evidence->excerpt,
            );
            $this->assertSame(
                Chunker::utf16Length($evidence->excerpt),
                $evidence->utf16_end - $evidence->utf16_start,
            );
        }
    }
}
