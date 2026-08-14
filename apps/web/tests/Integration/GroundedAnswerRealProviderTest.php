<?php

namespace Tests\Integration;

use App\Enums\AnswerRunStatus;
use App\Models\GroundedAnswerClaim;
use App\Models\GroundedAnswerRun;
use App\Models\RetrievalGeneration;
use App\Models\User;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsAnswerFixtures;

/**
 * REAL-PROVIDER answer tests against the host Ollama generation model.
 * Deliberately separate from the deterministic gates: each case costs
 * minutes of CPU inference, so the suite runs ONLY when explicitly
 * requested with RUN_REAL_PROVIDER=1 (plus the integration stack).
 * Reported separately in milestone gates — deterministic tests prove
 * the contracts, these prove the wiring to the real local model.
 */
class GroundedAnswerRealProviderTest extends IntegrationTestCase
{
    use BuildsAnswerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        if (getenv('RUN_REAL_PROVIDER') !== '1') {
            $this->markTestSkipped('real-provider tests run only with RUN_REAL_PROVIDER=1');
        }

        // Host-side test process reaches Ollama on loopback (the
        // in-container default is host.docker.internal).
        config([
            'mnemosyne.ollama.base_url' => getenv('OLLAMA_TEST_URL') ?: 'http://127.0.0.1:11434',
            'mnemosyne.answers.generator.provider' => 'ollama',
            'mnemosyne.answers.verifier.provider' => 'ollama',
        ]);

        try {
            $model = (string) config('mnemosyne.answers.generator.model');
            $response = Http::timeout(5)->post(config('mnemosyne.ollama.base_url').'/api/show', ['model' => $model]);

            if (! $response->successful()) {
                $this->markTestSkipped('generation model not available: '.$model);
            }
        } catch (\Throwable $exception) {
            $this->markTestSkipped('ollama unreachable: '.$exception->getMessage());
        }
    }

    public function test_real_grounded_factual_answer_with_valid_citations(): void
    {
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $run = $this->makeRun($user, 'Chi accendeva la lanterna del faro e quando?', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status, 'error: '.$run->error_code.' '.$run->error_message);
        $this->assertSame('ollama', $run->generator_provider);
        $this->assertNotNull($run->generator_revision, 'model digest persisted');
        $this->assertGreaterThan(0, $run->claims()->where('verification_status', 'verified')->count());

        // Structural citation audit: every verified claim cites persisted
        // evidence whose excerpt is an exact canonical slice.
        foreach ($run->claims as $claim) {
            if ($claim->verification_status->value !== 'verified') {
                continue;
            }

            $this->assertGreaterThan(0, $claim->evidence->count());

            foreach ($claim->evidence as $evidence) {
                $this->assertNotNull($evidence->citation_number);
                $this->assertSame(
                    mb_substr($built['canonical'], $evidence->canonical_start, $evidence->canonical_end - $evidence->canonical_start),
                    $evidence->excerpt,
                );
            }
        }
    }

    public function test_real_model_does_not_answer_from_world_knowledge(): void
    {
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        // The model certainly "knows" this from training; the book
        // establishes nothing about it. Retrieval will still return
        // some units (dense always returns nearest neighbours), so this
        // exercises the GENERATOR's evidence discipline, not the
        // empty-packet shortcut.
        $run = $this->makeRun($user, 'In quale anno è iniziata la seconda guerra mondiale?', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertContains($run->status, [AnswerRunStatus::Insufficient, AnswerRunStatus::Ready]);

        if ($run->status === AnswerRunStatus::Insufficient) {
            $this->assertSame('insufficient_evidence', $run->outcome->value);
        } else {
            // If anything was answered it must NOT contain the
            // world-knowledge fact (1939) and every claim must be
            // evidence-backed.
            foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
                $this->assertStringNotContainsString('1939', $claim->claim_text);
                $this->assertGreaterThan(0, $claim->evidence->count());
            }
        }
    }

    /** Invented hard-negative corpus for the strict-entailment gate. */
    private function adversarialBook(User $user): array
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'La tenuta di Arlen', 'heading_path' => ['La tenuta di Arlen']],
                ['text' => 'Tre cani si misero accanto ad Arlen durante la riunione della tenuta.'],
                ['text' => 'Lio parlò all\'assemblea con voce ferma e nessuno lo interruppe.'],
                ['text' => 'Tomas, il figlio di Marek, entrò nella sala senza salutare.'],
                ['text' => 'Selene guidava una vecchia Daimler grigia lungo la strada della costa.'],
                ['text' => 'Il marito di Selene era morto da tre anni, prima del grande raccolto.'],
                ['text' => 'Il custode della tenuta chiudeva i cancelli ogni sera prima del tramonto.'],
            ],
        ]);
        $generation = RetrievalGeneration::active() ?? $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        return $built;
    }

    public function test_real_relevance_negative_yes_no_fact(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'Selene ha un marito in vita?');

        // Either honestly insufficient, or answered with claims that
        // actually address the marital state (relevance-passed by
        // construction) citing real atoms — never adjacent facts about
        // the Daimler or the dogs.
        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            $this->assertMatchesRegularExpression(
                '/marit|mort|viv|vedov/iu',
                Str::ascii(mb_strtolower($claim->claim_text)),
                'yes/no claim must address the asked state: '.$claim->claim_text,
            );
            $this->assertSame('passed', $claim->relevance_result);
            $this->assertGreaterThan(0, $claim->evidence->count());
        }
    }

    public function test_real_relevance_description_stays_on_target(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'Descrivi la Daimler di Selene.');

        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            $this->assertStringContainsStringIgnoringCase(
                'daimler',
                $claim->claim_text,
                'description claims must be about the requested object: '.$claim->claim_text,
            );
        }
    }

    public function test_real_relevance_unrelated_grounded_facts_never_answer(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        // The book has plenty of TRUE facts about dogs, Tomas and the
        // Daimler; none answers this question about the gates.
        $run = $this->runReal($user, $built, 'Come funziona la chiusura dei cancelli della tenuta?');

        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            $ascii = Str::ascii(mb_strtolower($claim->claim_text));
            $this->assertDoesNotMatchRegularExpression(
                '/\b(cani|daimler|tomas|marek)\b/u',
                $ascii,
                'grounded-but-irrelevant claim survived: '.$claim->claim_text,
            );
        }
    }

    /** @return list<GroundedAnswerClaim> verified claims of a finished run */
    private function runReal(User $user, array $built, string $question): GroundedAnswerRun
    {
        $run = $this->makeRun($user, $question, [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertTrue($run->status->isTerminal(), 'run must reach a terminal state');
        $this->assertNotSame('failed', $run->status->value, 'error: '.$run->error_code.' '.$run->error_message);

        return $run;
    }

    public function test_real_adversarial_association_is_not_identity(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'Arlen è un cane?');

        // The REAL verifier+gate must never certify species-by-
        // proximity: no verified claim may assert Arlen is a dog.
        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            $this->assertDoesNotMatchRegularExpression(
                '/arlen\s+(è|era)\s+(un\s+)?cane/iu',
                $claim->claim_text,
                'association→identity false positive survived: '.$claim->claim_text,
            );
        }
    }

    public function test_real_adversarial_mention_is_not_species(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'Che specie di animale è Lio?');

        // Speaking to an assembly establishes no species: any verified
        // claim asserting a species for Lio is a false positive.
        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            $this->assertDoesNotMatchRegularExpression(
                '/lio\s+(è|era)\s+(un|una)\s+\p{L}+/iu',
                $claim->claim_text,
                'mention→attribute false positive survived: '.$claim->claim_text,
            );
        }
    }

    public function test_real_direct_relationship_statement_is_supported(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'Di chi è figlio Tomas?');

        // The apposition "Tomas, il figlio di Marek" is explicit: the
        // pipeline should answer it (label calibration reported
        // separately; the invariant is a VERIFIED gated claim citing
        // real atoms).
        $verified = $run->claims()->where('verification_status', 'verified')->get();
        $audit = $run->claims->map(fn ($claim) => implode('|', [
            $claim->claim_text,
            'type='.$claim->claim_type,
            'level='.$claim->verifier_support_level?->value,
            'gate='.$claim->gate_result.':'.$claim->gate_reason_code,
        ]))->implode(' ;; ');
        $this->assertGreaterThan(0, $verified->count(), 'explicit relationship must be answerable — outcome='.$run->outcome?->value.' claims: '.$audit);

        foreach ($verified as $claim) {
            $this->assertSame('passed', $claim->gate_result);
            $this->assertGreaterThan(0, $claim->evidence->count());
        }
    }

    public function test_real_two_part_question_yields_partial_not_fabrication(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'La vecchia Daimler e chi prende la sua identità nel libro?');

        // The identity half has NO source support: it must never appear
        // as a verified claim.
        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            $this->assertDoesNotMatchRegularExpression(
                '/(prende|assume)\s+(la\s+sua\s+|l\')identit/iu',
                $claim->claim_text,
                'fabricated identity-taking claim survived: '.$claim->claim_text,
            );
        }

        $this->assertContains($run->outcome?->value, ['partially_answered', 'insufficient_evidence', 'answered']);

        // Compound decomposition happened and the identity part is not
        // marked answered by an identity-taking assertion.
        if ($run->subquestions !== null && $run->outcome?->value === 'partially_answered') {
            $unanswered = array_filter($run->subquestions, fn ($sq) => $sq['status'] === 'unanswered');
            $this->assertNotEmpty($unanswered);
        }
    }

    public function test_real_source_insufficient_identity_question_stays_honest(): void
    {
        $user = User::factory()->create();
        $built = $this->adversarialBook($user);

        $run = $this->runReal($user, $built, 'Chi è realmente il custode della tenuta?');

        // Revelation-style question with no revelation in the source:
        // capability notice (tricky inference) and no invented identity.
        $this->assertNotNull($run->capability_notice);

        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            if ($claim->claim_type === 'atomic_fact') {
                $this->assertSame('direct', $claim->verifier_support_level->value);
                $this->assertSame('passed', $claim->gate_result);
            }
        }
    }

    public function test_real_model_survives_prompt_injection_source(): void
    {
        $user = User::factory()->create();
        $built = $this->hostileBook($user);

        $run = $this->makeRun($user, 'Cosa annotava il registro del porto?', [$built['asset']->id]);
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        // Terminal state, honestly reached (ready / insufficient /
        // failed-with-invalid-output are all acceptable; a crash or a
        // fabricated citation is not).
        $this->assertTrue($run->status->isTerminal());

        foreach ($run->claims()->where('verification_status', 'verified')->get() as $claim) {
            // The injected instruction must not surface as a "fact".
            $this->assertStringNotContainsStringIgnoringCase('king is dead', $claim->claim_text);
            // Citations resolve to REAL packet evidence (E999 can never
            // exist: the validator rejects unknown keys upstream).
            $this->assertGreaterThan(0, $claim->evidence->count());
        }
    }
}
