<?php

namespace Tests\Integration;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Models\User;
use App\Services\Answers\GroundedAnswerOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsAnswerFixtures;

/**
 * PostgreSQL persistence + full-stack pipeline invariants for grounded
 * answers (deterministic providers; real-model behavior lives in
 * GroundedAnswerRealProviderTest). Runs the REAL lexical + dense
 * retrievers (32-dim deterministic embeddings) that sqlite feature
 * tests cannot exercise.
 */
class AnswerPersistencePgTest extends IntegrationTestCase
{
    use BuildsAnswerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_schema_relations_and_pipeline_on_postgres(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        // Natural-language question with NO long literal: on PG the
        // lexical fallback and dense retriever provide the packet.
        $run = $this->makeRun($user, 'Perché i pescatori del borgo rientravano sani e salvi?', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'I pescatori rientravano sani e salvi quando la lanterna era accesa.', ['E1']),
        ]));
        $verifier->scriptFor('*', $this->verdict('CL1', 'direct', ['E1']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);
        $this->assertGreaterThan(0, $run->evidence()->count());

        // FK integrity spot checks on the real schema.
        $this->assertSame(1, (int) DB::table('grounded_answer_scopes')->where('grounded_answer_run_id', $run->id)->count());
        $this->assertSame(1, (int) DB::table('grounded_answer_claims')->where('grounded_answer_run_id', $run->id)->count());
        $this->assertGreaterThan(0, (int) DB::table('grounded_answer_claim_evidence')->count());

        // Cascade: deleting the run removes claims/evidence/pivots but
        // never touches the book or retrieval data.
        $chunksBefore = (int) DB::table('retrieval_chunks')->count();
        $run->delete();
        $this->assertSame(0, (int) DB::table('grounded_answer_claims')->count());
        $this->assertSame(0, (int) DB::table('grounded_answer_evidence')->count());
        $this->assertSame(0, (int) DB::table('grounded_answer_claim_evidence')->count());
        $this->assertSame($chunksBefore, (int) DB::table('retrieval_chunks')->count());
    }

    public function test_comparative_coverage_with_real_lexical_and_dense_components(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $corpus = $this->comparativeCorpus($user);

        $run = $this->makeRun(
            $user,
            'Confronta il ruolo del faro nei due libri',
            [$corpus['a']['asset']->id, $corpus['b']['asset']->id],
        );

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Nel primo libro il faro nord è il centro della vita del guardiano.', ['E1']),
            $this->claim('CL2', 'Nel secondo libro il faro è solo una metafora per la lampada della locanda.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1']));
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'strong', ['E2'], 'METAPHORICAL_USE'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame('comparative_multi_book', $run->classified_intent->value);

        // Both books contribute evidence to the packet on the real
        // retrievers, and citations keep correct per-book identity.
        $perAsset = $run->evidence->groupBy('book_asset_id')->map->count();
        $this->assertGreaterThan(0, $perAsset[$corpus['a']['asset']->id] ?? 0);
        $this->assertGreaterThan(0, $perAsset[$corpus['b']['asset']->id] ?? 0);

        foreach ($run->evidence as $evidence) {
            $this->assertSame(
                $evidence->book_title,
                $evidence->asset->edition?->title ?? $evidence->asset->original_filename,
            );
        }
    }

    public function test_migrations_applied_on_top_of_m2_schema_shape(): void
    {
        // RefreshDatabase ran ALL migrations (M1+M2+M3) on this fresh PG
        // database: assert the M3 tables landed with their uniques.
        $tables = collect(DB::select(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
        ))->pluck('tablename');

        foreach ([
            'conversations', 'conversation_messages', 'grounded_answer_runs',
            'grounded_answer_scopes', 'grounded_answer_evidence',
            'grounded_answer_claims', 'grounded_answer_claim_evidence',
        ] as $table) {
            $this->assertContains($table, $tables);
        }

        $uniques = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename IN
             ('grounded_answer_evidence', 'grounded_answer_claims', 'grounded_answer_claim_evidence')",
        ))->pluck('indexname');

        $this->assertContains('answer_evidence_key_unique', $uniques);
        $this->assertContains('answer_claim_key_unique', $uniques);
        $this->assertContains('claim_evidence_unique', $uniques);
    }
}
