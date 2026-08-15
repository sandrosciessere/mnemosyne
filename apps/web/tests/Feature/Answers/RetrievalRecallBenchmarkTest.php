<?php

namespace Tests\Feature\Answers;

use App\Enums\QueryIntent;
use App\Models\User;
use App\Services\Answers\EvidencePacketBuilder;
use App\Services\Answers\EvidenceSufficiencyProbe;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Answers\QueryReformulator;
use App\Services\Answers\RetrievalPolicyResolver;
use App\Services\Answers\TaskContractClassifier;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

/**
 * Third corrective pass — retrieval recall benchmark. Separates
 * candidate_count / packet_occupancy / relevant recall / final
 * answerability, and PROVES a top distractor chunk that splits into
 * many EvidenceUnits cannot monopolize the packet. Invented text only.
 */
class RetrievalRecallBenchmarkTest extends TestCase
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
     * Distractor-heavy corpus: spine 0 = one huge chunk of 12 sentences
     * all mentioning the entity "Orsola" (top hit for any Orsola query),
     * spine 1..3 = short scenes each carrying ONE decisive fact.
     */
    private function distractorBook(User $user): array
    {
        // Five ~85-char sentences (~430 chars): ONE chunk under the test
        // chunker (max 500), which then splits into 5 sentence atoms /
        // units — the monopolization pattern seen in owner QA.
        $distractor = [];
        for ($i = 1; $i <= 5; $i++) {
            $distractor[] = "Orsola attraversò la piazza del mercato per la {$i}ª volta salutando i venditori.";
        }

        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Il mercato', 'heading_path' => ['Il mercato']],
                ['text' => implode(' ', $distractor)],
            ],
            1 => [
                ['type' => 'heading', 'text' => 'La casa gialla', 'heading_path' => ['La casa gialla']],
                ['text' => 'La madre dei figli di Orsola era morta da anni: la vecchia casa gialla restava silenziosa.'],
            ],
            2 => [
                ['type' => 'heading', 'text' => 'La siepe', 'heading_path' => ['La siepe']],
                ['text' => 'Dall\'altra parte della siepe abitava Teodora, che ogni sera portava pane a Orsola.'],
            ],
            3 => [
                ['type' => 'heading', 'text' => 'Il fucile', 'heading_path' => ['Il fucile']],
                ['text' => 'Orsola imbracciò il fucile e colpì la banderuola al primo colpo; poi i suoi occhiali caddero e si frantumarono sul selciato.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        return $built + ['generation' => $generation];
    }

    public function test_distractor_chunk_cannot_monopolize_the_packet(): void
    {
        $user = User::factory()->create();
        $built = $this->distractorBook($user);

        config([
            'mnemosyne.answers.evidence.max_units' => 8,
            'mnemosyne.answers.evidence.max_initial_units_per_chunk' => 2,
        ]);

        // sqlite feature tests reach retrieval through the exact
        // component only: the entity literal "Orsola" occurs in EVERY
        // region, so the distractor chunk (12 sentences) competes with
        // the three decisive scenes on equal exact footing.
        $contract = (new TaskContractClassifier)->classify('SQ1', 'Orsola');
        $packet = app(EvidencePacketBuilder::class)->buildForSubquestions(
            $built['generation'], [$built['asset']->id],
            [['key' => 'SQ1', 'text' => 'Orsola']],
            (new RetrievalPolicyResolver)->resolve(QueryIntent::PointLookup, 1),
            ['SQ1' => $contract],
        );

        $stats = $packet->stats;

        // Occupancy is full but recall metrics are separate and diverse.
        $this->assertSame(8, $stats['units'], 'packet occupancy');
        $this->assertGreaterThanOrEqual(3, $stats['distinct_regions'], 'diverse source regions in packet: '.json_encode($stats));
        // Stage-1 caps held back at least the distractor's surplus OR the
        // packet already reached breadth: either way ALL three decisive
        // scenes (spines 1..3) must be represented in an 8-unit packet.
        $spines = array_unique(array_map(fn ($u) => $u->spineIndex, array_values($packet->units)));
        foreach ([1, 2, 3] as $decisiveSpine) {
            $this->assertContains($decisiveSpine, $spines, "decisive scene spine {$decisiveSpine} must be in the packet; spines=".json_encode(array_values($spines)));
        }

        // The distractor (spine 0) may not exceed the per-chunk cap in
        // stage 1: at least half of the packet must come from elsewhere.
        $fromDistractor = 0;
        foreach ($packet->units as $unit) {
            if ($unit->spineIndex === 0) {
                $fromDistractor++;
            }
        }
        $this->assertLessThanOrEqual(4, $fromDistractor, 'distractor chunk share bounded');
        $this->assertGreaterThan(0, $fromDistractor, 'but promising regions still get depth');

        // The DECISIVE spouse-state unit (spine 1) IS in the packet.
        $decisive = array_filter($packet->units, fn ($u) => $u->spineIndex === 1);
        $this->assertNotEmpty($decisive, 'decisive evidence must survive the distractor');

        // And the probe, evaluated for the REAL yes/no spouse contract,
        // recognises the decisive unit as candidate evidence.
        $spouseContract = (new TaskContractClassifier)->classify('SQ1', 'Orsola ha un marito in vita?');
        $probe = (new EvidenceSufficiencyProbe)->probe($spouseContract, $packet);
        $this->assertTrue($probe['likely_sufficient'], json_encode($probe));
    }

    public function test_relation_perspective_variants_are_generated_and_tagged(): void
    {
        $classifier = new TaskContractClassifier;
        $reformulator = new QueryReformulator;

        $wife = $reformulator->variants($classifier->classify('SQ1', 'Orsola ha un marito in vita?'));
        $joined = mb_strtolower(implode(' | ', $wife));
        $this->assertStringContainsString('vedov', $joined, 'perspective lexicon (widow) expected: '.$joined);
        $this->assertStringContainsString('mort', $joined, 'state opposite expected: '.$joined);
        $this->assertLessThanOrEqual(5, count($wife));

        $neighbors = $reformulator->variants($classifier->classify('SQ1', 'Chi sono i vicini di Orsola?'));
        $joined = mb_strtolower(implode(' | ', $neighbors));
        $this->assertStringContainsString('accanto', $joined);
        $this->assertStringContainsString('confinante', $joined, 'neighbor perspective expected: '.$joined);
        $this->assertStringNotContainsString('chi orsola', $joined, 'interrogative must not be treated as an entity');
        // Deeper perspectives ("dall'altra parte della strada") belong
        // to the focused expansion set, not the bounded first pass.
        $expansionNeighbors = $reformulator->expansionVariants($classifier->classify('SQ1', 'Chi sono i vicini di Orsola?'));
        $this->assertStringContainsString('parte della strada', mb_strtolower(implode(' | ', $expansionNeighbors)));

        // Expansion variants are distinct from base variants and bounded.
        $expansion = $reformulator->expansionVariants($classifier->classify('SQ1', 'Orsola ha un marito in vita?'), ['selciato', 'banderuola']);
        $this->assertNotEmpty($expansion);
        $this->assertLessThanOrEqual(4, count($expansion));
        $this->assertNotEmpty(array_diff($expansion, $wife), 'expansion must add NEW formulations');
    }

    public function test_query_hypotheses_never_enter_evidence_provenance(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->distractorBook($user);

        $run = $this->makeRun($user, 'Orsola ha un marito in vita?', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'No: la madre dei figli di Orsola era morta da anni.', ['E1']),
        ]));
        // The verifier cites whichever unit is the "casa gialla" one; find
        // it by scripting against ANY unit and letting validation pick —
        // simplest: run once, then locate the decisive key.
        app(GroundedAnswerOrchestrator::class); // boot
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'none', [], 'NO_MENTION'));
        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        // Provenance rows are ALWAYS canonical source slices: no
        // reformulated query text, no perspective lexicon, no
        // retrieval_hypothesis string ever appears as an excerpt.
        foreach ($run->evidence as $evidence) {
            $this->assertSame(
                mb_substr($built['canonical'], $evidence->canonical_start, $evidence->canonical_end - $evidence->canonical_start),
                $evidence->excerpt,
            );
            $this->assertStringNotContainsString('vedov', $evidence->excerpt);
        }

        // Diagnostics DO record the hypotheses (auditable, separate).
        $queries = array_column($run->retrieval_diagnostics['searches'] ?? [], 'query');
        $this->assertGreaterThan(1, count($queries));
    }

    public function test_focused_expansion_fires_when_probe_finds_no_candidate_evidence(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();

        // Book where the entity is everywhere but the asked relation is
        // NOWHERE in the first-pass regions and appears only under a
        // perspective formulation ("padre dei figli") in a far spine.
        $filler = [];
        for ($i = 1; $i <= 10; $i++) {
            $filler[] = "Bruno camminava lungo il fiume pensando alla {$i}ª partita a scacchi persa contro il notaio.";
        }
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Il fiume', 'heading_path' => ['Il fiume']],
                ['text' => implode(' ', $filler)],
            ],
            1 => [
                ['type' => 'heading', 'text' => 'Il notaio', 'heading_path' => ['Il notaio']],
                ['text' => 'Il notaio vinceva a scacchi ogni domenica e Bruno perdeva con eleganza.'],
            ],
            2 => [
                ['type' => 'heading', 'text' => 'Casa', 'heading_path' => ['Casa']],
                ['text' => 'La donna che era stata la madre dei figli di Bruno era vedova di guerra e viveva ancora con lui.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        // sqlite feature tests exercise the exact retriever only: the
        // base variants ("moglie", "in vita", "morta") match nothing,
        // the perspective/expansion query ("madre dei figli") matches
        // spine 2 → the expansion is what brings the evidence.
        $run = $this->makeRun($user, 'Bruno ha una moglie in vita?', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Sì: la madre dei figli di Bruno viveva ancora con lui.', ['E1']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1.S1']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $diag = $run->retrieval_diagnostics;
        $this->assertSame(1, $run->retrieval_expansion_count, 'the single focused expansion must fire: '.json_encode($diag['sufficiency_probe'] ?? null));
        $this->assertSame('SQ1', $diag['expansion_target']);
        $this->assertNotEmpty($diag['expansion_queries']['SQ1'] ?? [], 'dedicated expansion queries persisted');
        $this->assertNotSame('THIN_PACKET', $diag['expansion_trigger'], 'trigger must be sufficiency-based, not unit count');
    }
}
