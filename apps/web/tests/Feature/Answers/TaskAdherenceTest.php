<?php

namespace Tests\Feature\Answers;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Enums\ClaimVerificationStatus;
use App\Models\BookAsset;
use App\Models\RetrievalGeneration;
use App\Models\User;
use App\Services\Answers\AnswerPresenter;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Answers\QuestionWellFormednessGate;
use App\Services\Answers\TaskContract;
use App\Services\Answers\TaskContractClassifier;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

/**
 * Second corrective pass release gates: SOURCE-FAITHFUL claims that do
 * not ANSWER THE QUESTION must never be displayed; unsupported global
 * tasks short-circuit honestly; outcome semantics come from material
 * task coverage. All invented text/names.
 */
class TaskAdherenceTest extends TestCase
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

    /**
     * Invented corpus: one well-described object (device), one
     * character with facts, one relationship, one negative marital
     * state, one dangling-reference trap.
     *
     * @return array{asset: BookAsset, generation: RetrievalGeneration, canonical: string}
     */
    private function adherenceBook(User $user): array
    {
        // sqlite feature tests reach retrieval through the exact
        // component only, so each scenario embeds its question as a
        // literal; PG integration + real QA cover true recall.
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Il dispositivo di Corte', 'heading_path' => ['Il dispositivo di Corte']],
                ['text' => 'Tutti chiedevano: come funziona il dispositivo di ottone? Una leva apre la valvola e l\'acqua scende per tre minuti.'],
                ['text' => 'La musica esisteva solo mentre qualcuno azionava la leva.'],
                ['text' => 'Il custode Ansaldo archiviava ogni fascicolo nella torre.'],
            ],
            1 => [
                ['type' => 'heading', 'text' => 'La siepe di bosso', 'heading_path' => ['La siepe di bosso']],
                ['text' => 'Qualcuno chiese: chi sono i vicini di Mara? Lena viveva nella casa accanto a Mara, oltre la siepe di bosso.'],
                ['text' => 'La domanda ricorreva: Mara ha un marito in vita? Il marito di Mara era morto tre anni prima, durante il lungo inverno.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        return $built;
    }

    // ══════════════════ Task contract classification ══════════════════

    public function test_task_contract_classification(): void
    {
        $classifier = new TaskContractClassifier;

        $topN = $classifier->classify('SQ1', 'Fammi un elenco dei 10 personaggi principali del libro.');
        $this->assertSame(TaskContract::TOP_N_RANKING, $topN->taskType);
        $this->assertSame(10, $topN->requestedCount);
        $this->assertSame('global', $topN->coverageRequirement);
        $this->assertFalse($topN->supportedInM3);

        $longitudinal = $classifier->classify('SQ1', 'Il violino rimane sempre uguale a livello di forma e fattezze?');
        $this->assertSame(TaskContract::TEMPORAL_EVOLUTION, $longitudinal->taskType);
        $this->assertFalse($longitudinal->supportedInM3);

        $description = $classifier->classify('SQ2', 'Descrivi il violino.');
        $this->assertSame(TaskContract::LOCAL_DESCRIPTION, $description->taskType);
        $this->assertTrue($description->supportedInM3);

        $reveal = $classifier->classify('SQ1', 'A che punto del libro si capisce che chi narra non è effettivamente Jeno?');
        $this->assertSame(TaskContract::IDENTITY_REVEAL, $reveal->taskType);
        $this->assertSame(TaskContract::SHAPE_LOCATION, $reveal->answerShape);
        $this->assertFalse($reveal->supportedInM3);

        $neighbors = $classifier->classify('SQ1', 'Chi sono i vicini di Mara?');
        $this->assertSame(TaskContract::RELATIONSHIP_LOOKUP, $neighbors->taskType);
        $this->assertSame('neighbor', $neighbors->relationshipType);
        $this->assertSame(TaskContract::SHAPE_LIST, $neighbors->answerShape);
        $this->assertTrue($neighbors->supportedInM3);

        $wife = $classifier->classify('SQ1', 'Mara ha un marito in vita?');
        $this->assertSame(TaskContract::YES_NO_FACT, $wife->taskType);
        $this->assertSame('spouse', $wife->relationshipType);
        $this->assertTrue($wife->supportedInM3);

        $mechanism = $classifier->classify('SQ1', 'Come funziona il dispositivo?');
        $this->assertSame(TaskContract::LOCAL_EXPLANATION, $mechanism->taskType);
        $this->assertTrue($mechanism->supportedInM3);
    }

    public function test_wellformedness_gate_high_confidence_only(): void
    {
        $gate = new QuestionWellFormednessGate;

        $this->assertFalse($gate->check('Come si evolve il a livello di forma? Rimane sempre uguale? Descrivilio.')['well_formed']);
        $this->assertFalse($gate->check('Descrivi la ?')['well_formed']);

        // Normal shorthand, pronouns and typos must NOT trigger.
        $this->assertTrue($gate->check('Come si evolve il violino a livello di forma?')['well_formed']);
        $this->assertTrue($gate->check('Descrivilo.')['well_formed']);
        $this->assertTrue($gate->check('Chi sono i vicini di Scout e Atricus?')['well_formed']);
        $this->assertTrue($gate->check('E lui cosa fa dopo?')['well_formed']);
    }

    // ══════════════════ H. needs_clarification ══════════════════

    public function test_h_ambiguous_question_requests_clarification_cheaply(): void
    {
        [$generator] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Come si evolve il a livello di forma? Rimane sempre uguale? Descrivilio.', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame(AnswerOutcome::NeedsClarification, $run->outcome);
        // No expensive model work at all.
        $this->assertCount(0, $generator->calls, 'generator must not run for a malformed question');
        $this->assertNull($run->generator_provider);
        $this->assertSame(0, $run->evidence()->count(), 'no retrieval persisted either');
        $this->assertNotEmpty($run->subquestions);
        $this->assertSame('needs_clarification', $run->subquestions[0]['status']);
    }

    // ══════════════════ A. Global top-N short-circuit ══════════════════

    public function test_a_global_topn_short_circuits_without_generation(): void
    {
        [$generator] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Fammi un elenco dei cinque personaggi principali del libro.', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
        $this->assertSame(AnswerOutcome::InsufficientEvidence, $run->outcome);
        $this->assertNotNull($run->capability_notice);
        $this->assertCount(0, $generator->calls, 'no generation for an unsupported global ranking');
        $this->assertSame('capability_limited', $run->subquestions[0]['status']);
        $this->assertSame('CAPABILITY_UNSUPPORTED', $run->subquestions[0]['diagnosis']);
        // Grounded-but-irrelevant facts about one character can never
        // appear: nothing was generated at all.
        $this->assertCount(0, $run->claims);
    }

    // ══════════════════ B. Longitudinal short-circuit ══════════════════

    public function test_b_longitudinal_claim_cannot_come_from_one_local_snapshot(): void
    {
        [$generator] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'La forma del dispositivo rimane sempre uguale nel corso del libro?', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
        $this->assertNotNull($run->capability_notice);
        $this->assertCount(0, $generator->calls);
        $this->assertSame('capability_limited', $run->subquestions[0]['status']);
    }

    // ══════════════════ C+G. Direct description / mechanism ══════════════════

    public function test_cg_explicit_mechanism_yields_answered_with_textual_facts(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Come funziona il dispositivo di ottone?', [$built['asset']->id]);

        // Five claims; one gets rejected by the verifier — outcome must
        // STILL be answered (coverage, not counts). Claims restate the
        // explicit mechanism → faithful-restatement calibration promotes
        // strong to Fatto testuale.
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Una leva apre la valvola del dispositivo.', ['E2']),
            $this->claim('CL2', 'L\'acqua del dispositivo scende per tre minuti.', ['E2'], 'strong_inference'),
            $this->claim('CL3', 'Una leva apre la valvola del dispositivo e l\'acqua scende per tre minuti.', ['E2'], 'strong_inference'),
            $this->claim('CL4', 'Il dispositivo fu costruito da un fabbro straniero.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2.S2']));
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'strong', ['E2.S2'], 'RESTATES_SOURCE'));
        $verifier->scriptFor('CL3', $this->verdict('CL3', 'strong', ['E2.S2'], 'RESTATES_SOURCE'));
        $verifier->scriptFor('CL4', $this->verdict('CL4', 'none', [], 'NO_MENTION'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome, 'rejected extra claim must not force partial');

        $verified = $run->claims->where('verification_status', ClaimVerificationStatus::Verified);
        $this->assertCount(3, $verified);

        // Faithful restatements of explicit source → textual_fact.
        $labels = $verified->pluck('final_label')->map(fn ($label) => $label->value);
        $this->assertContains('textual_fact', $labels, 'direct calibration must promote faithful restatements');
        $this->assertGreaterThanOrEqual(3, $labels->filter(fn ($l) => $l === 'textual_fact')->count());
    }

    // ══════════════════ D. Reveal answered with adjacent facts ══════════════════

    public function test_d_identity_reveal_never_accepts_adjacent_character_facts(): void
    {
        [$generator] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        // The task contract short-circuits the reveal BEFORE generation:
        // "reasons" claims from passages about the same characters can
        // never be displayed because generation never happens.
        $run = $this->makeRun($user, 'A che punto si capisce che chi narra non è effettivamente Ansaldo?', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
        $this->assertSame('LIMITED_TRICKY_INFERENCE_SUPPORT', $run->capability_notice);
        $this->assertCount(0, $generator->calls);
        $this->assertCount(0, $run->claims);
    }

    // ══════════════════ E. Neighbor relationship accepted ══════════════════

    public function test_e_neighbor_relationship_is_answerable(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Chi sono i vicini di Mara?', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Lena era la vicina di Mara: viveva nella casa accanto, oltre la siepe.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2.S2']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status, 'err: '.$run->error_code);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);
        $claim = $run->claims->first();
        $this->assertSame('passed', $claim->relevance_result);

        // Retrieval used relation-aware variants (persisted diagnostics).
        $queries = array_column($run->retrieval_diagnostics['searches'] ?? [], 'query');
        $this->assertGreaterThan(1, count($queries), 'multi-query retrieval expected');
    }

    // ══════════════════ F. Negative marital state supported ══════════════════

    public function test_f_negative_yes_no_fact_is_answerable(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Mara ha un marito in vita?', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'No: il marito di Mara era morto tre anni prima.', ['E3']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E3.S2']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status, 'err: '.$run->error_code);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);
        $this->assertSame('passed', $run->claims->first()->relevance_result);

        // State-opposite variant ran ("marito morto" reachable even
        // though the question says "in vita").
        $queries = implode(' | ', array_column($run->retrieval_diagnostics['searches'] ?? [], 'query'));
        $this->assertStringContainsString('morta', mb_strtolower($queries).' ', 'state-opposite variant expected: '.$queries);
    }

    // ══════════════════ §65 Relevance gate: zero false positives ══════════════════

    public function test_grounded_but_irrelevant_claims_are_rejected(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        // Question about the DEVICE mechanism; the misbehaving generator
        // answers with true-but-irrelevant claims: wrong entity (Ansaldo
        // archives), wrong attribute (music existence). The verifier
        // (misbehaving too) certifies them as direct.
        $run = $this->makeRun($user, 'Come funziona il dispositivo di ottone?', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il custode Ansaldo archiviava ogni fascicolo con cura.', ['E4']),
            $this->claim('CL2', 'Una leva apre la valvola e l\'acqua scende per tre minuti.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E4.S1'], 'DIRECTLY_STATED'));
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'direct', ['E2.S2'], 'DIRECTLY_STATED'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);

        // CL1 (wrong entity — Ansaldo's archiving has nothing to do
        // with the device) rejected by the relevance gate.
        $cl1 = $run->claims->firstWhere('claim_key', 'CL1');
        $this->assertSame('rejected', $cl1->verification_status->value);
        $this->assertSame('rejected', $cl1->relevance_result);

        // CL2 answers the mechanism: survives.
        $cl2 = $run->claims->firstWhere('claim_key', 'CL2');
        $this->assertSame('verified', $cl2->verification_status->value);

        // API: only relevant claims displayed.
        $presented = app(AnswerPresenter::class)->present($run);
        foreach ($presented['claims'] as $claim) {
            $this->assertStringNotContainsString('archiviava', $claim['text']);
        }
    }

    public function test_verifier_non_responsive_bit_rejects_claim(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Come funziona il dispositivo di ottone?', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il dispositivo di ottone era custodito nella torre.', ['E2']),
        ]));
        // Advisory bit says the claim does not answer the question.
        $verifier->scriptFor('CL1', [
            'claim_key' => 'CL1', 'support_level' => 'direct',
            'supported_atom_keys' => ['E2.S2'], 'reason_code' => 'DIRECTLY_STATED',
            'answers_subquestion' => false,
        ]);

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $claim = $run->claims->first();
        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('VERIFIER_MARKED_NON_RESPONSIVE', $claim->relevance_reason_code);
        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
    }

    // ══════════════════ §66 F/G: claim-local vs systemic protocol ══════════════════

    public function test_claim_local_verifier_malformation_does_not_destroy_valid_claims(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->adherenceBook($user);

        $run = $this->makeRun($user, 'Come funziona il dispositivo di ottone?', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Una leva apre la valvola e l\'acqua scende per tre minuti.', ['E2']),
            $this->claim('CL2', 'Una leva apre la valvola del dispositivo.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2.S2']));
        // CL2's verdict cites a fabricated atom BOTH times → claim-local
        // rejection, NOT a run failure.
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'direct', ['E77.S9']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status, 'valid claim must survive: '.$run->error_code);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);

        $cl2 = $run->claims->firstWhere('claim_key', 'CL2');
        $this->assertSame('rejected', $cl2->verification_status->value);
        $this->assertSame('VERIFIER_INVALID_SUPPORT_ATOM', $cl2->verifier_reason_code);

        $cl1 = $run->claims->firstWhere('claim_key', 'CL1');
        $this->assertSame('verified', $cl1->verification_status->value);

        // Verification duration persisted.
        $this->assertArrayHasKey('verification', $run->timings_ms);
    }
}
