<?php

namespace Tests\Feature\Answers;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Enums\ClaimVerificationStatus;
use App\Enums\EpistemicLabel;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\Answers\AnswerPresenter;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class AnswerPipelineTest extends TestCase
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

    public function test_full_pipeline_produces_verified_claims_with_server_side_citations(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'pescatori del borgo', [$built['asset']->id]);

        // The fake echoes a two-claim answer; both claims share E1 and
        // the second adds E2 → citation numbers must be assigned by
        // first appearance and REUSED across claims. CL2 is a
        // non-atomic inference with two independent atoms (the gate
        // requires >= 2 independent sources for strong claims).
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'I pescatori rientravano sani e salvi quando la lanterna era accesa.', ['E1']),
            $this->claim('CL2', 'I pescatori si affidavano alla lanterna per rientrare.', ['E1', 'E2'], 'strong_inference'),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1']));
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'strong', ['E1', 'E2'], 'MULTIPLE_PREMISES_SUPPORT'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);
        $this->assertNotNull($run->completed_at);
        $this->assertSame('fake', $run->generator_provider);
        $this->assertSame('fake', $run->verifier_provider);
        $this->assertNotNull($run->classified_intent);
        $this->assertSame('query-intent 1.1.0', $run->query_classifier_version);
        $this->assertSame('evidence-unitizer 1.1.0', $run->evidence_unitizer_version);
        $this->assertSame('grounded-generator 1.1.0', $run->generator_prompt_version);
        $this->assertSame('grounded-verifier 1.2.0', $run->verifier_prompt_version);
        $this->assertIsArray($run->timings_ms);
        $this->assertArrayHasKey('generation', $run->timings_ms);
        $this->assertArrayHasKey('verification', $run->timings_ms);

        $claims = $run->claims;
        $this->assertCount(2, $claims);
        $this->assertSame(EpistemicLabel::TextualFact, $claims[0]->final_label);
        $this->assertSame(EpistemicLabel::StrongInference, $claims[1]->final_label);
        $this->assertSame(ClaimVerificationStatus::Verified, $claims[0]->verification_status);

        // Citations: E1 first appearance → [1]; E2 → [2]; E1 reused by
        // CL2 keeps number 1.
        $evidence = $run->evidence->keyBy('evidence_key');
        $this->assertSame(1, $evidence['E1']->citation_number);
        $this->assertSame(2, $evidence['E2']->citation_number);
        $this->assertSame(
            [1],
            $claims[0]->evidence->pluck('citation_number')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [1, 2],
            $claims[1]->evidence->pluck('citation_number')->all(),
        );

        // Assistant message references the run and stores NO prose.
        $assistant = ConversationMessage::query()
            ->where('grounded_answer_run_id', $run->id)
            ->where('role', 'assistant')
            ->first();
        $this->assertNotNull($assistant);
        $this->assertNull($assistant->content);

        // Evidence snapshot invariant: excerpt is the exact canonical slice.
        foreach ($run->evidence as $item) {
            $this->assertSame(
                mb_substr($built['canonical'], $item->canonical_start, $item->canonical_end - $item->canonical_start),
                $item->excerpt,
            );
            $this->assertSame(hash('sha256', $item->excerpt), $item->text_hash);
            $this->assertNull($item->epub_cfi, 'CFI must never be invented');
        }
    }

    public function test_verifier_rejection_hides_claim_and_marks_partial(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'pescatori del borgo', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'I pescatori rientravano quando la lanterna era accesa.', ['E1']),
            $this->claim('CL2', 'Il sindaco era il fratello segreto di Marta.', ['E1']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1']));
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'none', [], 'NO_MENTION'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        // Coverage semantics: the surviving claim answers the whole
        // simple question — rejected EXTRA claims never force partial.
        $this->assertSame(AnswerOutcome::Answered, $run->outcome);

        $rejected = $run->claims->firstWhere('claim_key', 'CL2');
        $this->assertSame(ClaimVerificationStatus::Rejected, $rejected->verification_status);
        $this->assertNull($rejected->final_label);
        $this->assertCount(0, $rejected->evidence, 'rejected claims never carry citations');

        // The API presentation must NOT include the rejected claim.
        $presented = app(AnswerPresenter::class)->present($run);
        $this->assertCount(1, $presented['claims']);
        $this->assertSame(1, $presented['rejected_claim_count']);
    }

    public function test_all_claims_rejected_becomes_insufficient_evidence(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'pescatori del borgo', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Affermazione non supportata.', ['E1']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'none', [], 'NO_MENTION'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
        $this->assertSame(AnswerOutcome::InsufficientEvidence, $run->outcome);
    }

    public function test_generator_insufficient_evidence_is_honest_success(): void
    {
        [$generator] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'pescatori del borgo', [$built['asset']->id]);
        $generator->scriptOutput(['status' => 'insufficient_evidence', 'claims' => []]);

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
        $this->assertSame(AnswerOutcome::InsufficientEvidence, $run->outcome);
        $this->assertNull($run->error_code, 'insufficient evidence is not an error');
    }

    public function test_conflict_support_level_maps_to_conflict_label(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();

        // Conflict corpus: two incompatible statements about the same fact.
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Versione del nord', 'heading_path' => ['Versione del nord']],
                ['text' => 'Secondo il registro del nord, il ponte di Alvara fu completato nel 1721 dopo due anni di lavori.'],
            ],
            1 => [
                ['type' => 'heading', 'text' => 'Versione del sud', 'heading_path' => ['Versione del sud']],
                ['text' => 'Le cronache del sud affermano invece che il ponte di Alvara non fu mai terminato e crollò ancora incompleto.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        $run = $this->makeRun($user, 'ponte di Alvara', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il ponte di Alvara fu completato nel 1721.', ['E1']),
        ]));
        // Independent verifier detects that the packet contains
        // materially incompatible statements.
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'conflict', ['E1', 'E2'], 'SOURCES_DISAGREE'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $claim = $run->claims->first();
        $this->assertSame(EpistemicLabel::Conflict, $claim->final_label);
        $this->assertSame('SOURCES_DISAGREE', $claim->verifier_reason_code);
        // Both conflicting sources are exposed as citations.
        $this->assertCount(2, $claim->evidence);

        $presented = app(AnswerPresenter::class)->present($run);
        $this->assertSame('Contraddizione rilevata', $presented['claims'][0]['label_user']);
        $this->assertCount(2, $presented['claims'][0]['citations']);
    }

    public function test_verifier_can_select_better_evidence_than_generator_proposed(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'la lanterna era accesa', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'La lanterna proteggeva i pescatori.', ['E1']),
        ]));
        // Verifier swaps in a different packet unit; single-source
        // strong requires an explicit entailment certification for the
        // gate.
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'strong', ['E2'], 'LOGICAL_ENTAILMENT'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $claim = $run->claims->first();
        $this->assertSame(['E2'], $claim->evidence->pluck('evidence_key')->all());
        $this->assertSame(1, $claim->evidence->first()->citation_number);
    }

    public function test_empty_packet_short_circuits_to_insufficient_after_expansion(): void
    {
        $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        // Question with no literal match anywhere → empty packet on
        // sqlite (exact-only): the pipeline must expand once, then
        // conclude insufficient WITHOUT calling the generator.
        $run = $this->makeRun($user, 'zxqwvutopolino inesistente parola', [$built['asset']->id]);

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Insufficient, $run->status);
        $this->assertSame(AnswerOutcome::InsufficientEvidence, $run->outcome);
        $this->assertSame(1, $run->retrieval_expansion_count);
        $this->assertNull($run->generator_provider, 'generator must not run on an empty packet');
    }
}
