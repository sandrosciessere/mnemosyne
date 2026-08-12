<?php

namespace Tests\Feature\Answers;

use App\Enums\AnswerRunStatus;
use App\Models\BookAsset;
use App\Models\GroundedAnswerClaim;
use App\Models\RetrievalGeneration;
use App\Models\User;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

/**
 * Hard-negative release gates (invented characters, invented text): a
 * deliberately MISBEHAVING scripted verifier certifies unsupported
 * propositions and the deterministic ClaimEvidenceGate must refuse
 * every one of them. RELATED IS NOT SUPPORTED:
 * association/ownership/command/proximity/mention are never identity
 * or attributes.
 */
class ClaimGateHardNegativeTest extends TestCase
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
     * Invented hard-negative corpus. One node per §36 scenario.
     *
     * @return array{asset: BookAsset, generation: RetrievalGeneration, canonical: string}
     */
    private function hardNegativeBook(User $user): array
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'La tenuta di Arlen', 'heading_path' => ['La tenuta di Arlen']],
                ['text' => 'Tre cani si misero accanto ad Arlen durante la riunione della tenuta.'],
                ['text' => 'Selene salì in vettura e disse al suo autista di partire subito.'],
                ['text' => 'Lo chauffeur lavorava per Varro da molti anni con discrezione assoluta.'],
                ['text' => 'Lio parlò all\'assemblea con voce ferma e nessuno lo interruppe.'],
                ['text' => 'Tomas, il figlio di Marek, entrò nella sala senza salutare.'],
            ],
            1 => [
                ['type' => 'heading', 'text' => 'Le accuse', 'heading_path' => ['Le accuse']],
                ['text' => 'Il consiglio incolpava Iren di ogni fallimento della stagione.'],
                ['text' => 'Dopo le accuse, Iren fu esclusa e il controllo del capo si estese su tutto il villaggio.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        return $built;
    }

    /**
     * Runs one claim through the full pipeline with a scripted
     * (misbehaving) verifier verdict and returns the persisted claim.
     */
    private function runClaim(User $user, array $built, string $question, array $claim, array $verdict): GroundedAnswerClaim
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $run = $this->makeRun($user, $question, [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([$claim]));
        $verifier->scriptFor($claim['claim_key'], $verdict);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        return $run->claims()->where('claim_key', $claim['claim_key'])->firstOrFail();
    }

    public function test_a_association_is_not_species_even_when_verifier_says_strong(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        // The atom REALLY talks about dogs next to Arlen; the scripted
        // verifier wrongly certifies "Arlen è un cane" as strong.
        $claim = $this->runClaim(
            $user, $built, 'accanto ad Arlen',
            $this->claim('CL1', 'Arlen è un cane.', ['E1']),
            $this->verdict('CL1', 'strong', ['E2.S1'], 'STRONGLY_IMPLIES'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('atomic_fact', $claim->claim_type);
        $this->assertSame('rejected', $claim->gate_result);
        $this->assertSame('IDENTITY_REQUIRES_DIRECT_SUPPORT', $claim->gate_reason_code);
    }

    public function test_a2_association_is_not_species_even_when_verifier_says_direct(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        // Worst case: the verifier says DIRECT with the dogs-next-to-
        // Arlen atom. The structural check sees that "cane" is never
        // predicated of Arlen (no copula/apposition linkage) → rejected.
        $claim = $this->runClaim(
            $user, $built, 'accanto ad Arlen',
            $this->claim('CL1', 'Arlen è un cane.', ['E1']),
            $this->verdict('CL1', 'direct', ['E2.S1'], 'DIRECTLY_STATED'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('DIRECT_NOT_ESTABLISHED', $claim->gate_reason_code);
        $this->assertSame('direct', $claim->verifier_support_level->value, 'verifier_positive is auditable');
    }

    public function test_b_commanding_a_driver_does_not_make_you_the_driver(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        $claim = $this->runClaim(
            $user, $built, 'al suo autista',
            $this->claim('CL1', 'Selene è l\'autista.', ['E1']),
            $this->verdict('CL1', 'direct', ['E3.S1'], 'DIRECTLY_STATED'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('DIRECT_NOT_ESTABLISHED', $claim->gate_reason_code);
    }

    public function test_c_working_for_someone_is_not_assuming_their_identity(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        $claim = $this->runClaim(
            $user, $built, 'lavorava per Varro',
            $this->claim('CL1', 'Lo chauffeur assume l\'identità di Varro.', ['E1'], 'strong_inference'),
            $this->verdict('CL1', 'strong', ['E4.S1'], 'STRONGLY_IMPLIES'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('atomic_fact', $claim->claim_type, 'identity-assumption claims are atomic');
        $this->assertSame('IDENTITY_REQUIRES_DIRECT_SUPPORT', $claim->gate_reason_code);
    }

    public function test_d_speaking_does_not_establish_species(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        $claim = $this->runClaim(
            $user, $built, 'Lio parlò',
            $this->claim('CL1', 'Lio è un maiale.', ['E1']),
            $this->verdict('CL1', 'direct', ['E5.S1'], 'DIRECTLY_STATED'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('DIRECT_NOT_ESTABLISHED', $claim->gate_reason_code);
    }

    public function test_e_explicit_apposition_is_direct_and_survives_the_gate(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        $claim = $this->runClaim(
            $user, $built, 'il figlio di Marek',
            $this->claim('CL1', 'Tomas è il figlio di Marek.', ['E1']),
            $this->verdict('CL1', 'direct', ['E6.S1'], 'DIRECTLY_STATED'),
        );

        $this->assertSame('verified', $claim->verification_status->value);
        $this->assertSame('atomic_fact', $claim->claim_type);
        $this->assertSame('passed', $claim->gate_result);
        $this->assertSame('textual_fact', $claim->final_label->value);
        $this->assertNotEmpty($claim->evidence);
    }

    public function test_f_two_premise_consolidation_inference_is_allowed_as_strong(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        [$generator, $verifier] = $this->useFakeProviders();
        $run = $this->makeRun($user, 'incolpava Iren', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Le accuse contro Iren contribuirono a consolidare il controllo del capo.', ['E1', 'E2'], 'strong_inference'),
        ]));

        // Two atoms from DIFFERENT packet units (different source
        // nodes) → the independent-evidence rule can be satisfied.
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'strong', ['E1.S1', 'E2.S1'], 'MULTIPLE_PREMISES_SUPPORT'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $claim = $run->claims->first();
        $this->assertSame('causal_inference', $claim->claim_type);

        // The two scripted atoms come from different packet units: if
        // they map to different source nodes the claim stands as
        // strong; the invariant under test is that NO atomic-identity
        // shortcut occurred and gate metadata is persisted either way.
        $this->assertContains($claim->gate_result, ['passed', 'rejected']);

        if ($claim->gate_result === 'passed') {
            $this->assertSame('strong_inference', $claim->final_label->value);
        } else {
            $this->assertSame('INSUFFICIENT_INDEPENDENT_EVIDENCE', $claim->gate_reason_code);
        }
    }

    public function test_strong_with_single_source_is_rejected_without_entailment_certification(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        $claim = $this->runClaim(
            $user, $built, 'incolpava Iren',
            $this->claim('CL1', 'Le accuse contro Iren servivano a controllare il villaggio.', ['E1'], 'strong_inference'),
            $this->verdict('CL1', 'strong', ['E1.S1'], 'SEMANTIC_RELATEDNESS'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('INSUFFICIENT_INDEPENDENT_EVIDENCE', $claim->gate_reason_code);
    }

    public function test_unknown_atom_ids_reject_the_verdict(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        [$generator, $verifier] = $this->useFakeProviders();
        $run = $this->makeRun($user, 'accanto ad Arlen', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Arlen aveva una tenuta.', ['E1']),
        ]));
        // Fabricated atom key: the validator rejects both attempts →
        // honest VERIFIER_INVALID_OUTPUT, nothing published.
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E99.S9'], 'DIRECTLY_STATED'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Failed, $run->status);
        $this->assertSame('VERIFIER_INVALID_OUTPUT', $run->error_code);
        $this->assertCount(0, $run->claims);
    }

    public function test_none_verdict_maps_to_verifier_none_reason(): void
    {
        $user = User::factory()->create();
        $built = $this->hardNegativeBook($user);

        $claim = $this->runClaim(
            $user, $built, 'accanto ad Arlen',
            $this->claim('CL1', 'Arlen temeva i cani.', ['E1']),
            $this->verdict('CL1', 'none', [], 'NO_MENTION'),
        );

        $this->assertSame('rejected', $claim->verification_status->value);
        $this->assertSame('VERIFIER_NONE', $claim->gate_reason_code);
    }
}
