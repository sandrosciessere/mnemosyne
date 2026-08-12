<?php

namespace Tests\Feature\Answers;

use App\Enums\AnswerOutcome;
use App\Enums\AnswerRunStatus;
use App\Models\RetrievalChunk;
use App\Models\User;
use App\Services\Answers\AnswerPresenter;
use App\Services\Answers\EvidenceUnitizer;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Answers\QuestionDecomposer;
use App\Services\Answers\ResponseLanguageDetector;
use App\Services\Retrieval\Chunker;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class DecompositionAndPartialAnswerTest extends TestCase
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

    public function test_decomposer_splits_compound_questions_and_keeps_simple_ones_whole(): void
    {
        $decomposer = new QuestionDecomposer;

        $compound = $decomposer->decompose('Lo zio Gustav che auto guida e quindi chi prende la sua identità nel libro?');
        $this->assertCount(2, $compound);
        $this->assertSame('SQ1', $compound[0]['key']);
        $this->assertStringContainsString('che auto guida', $compound[0]['text']);
        $this->assertStringContainsString('chi prende la sua identità', $compound[1]['text']);

        $multi = $decomposer->decompose('Chi è Marta? Dove vive? Perché accendeva la lanterna? Quando lo faceva? E come mai da sola?');
        $this->assertLessThanOrEqual(QuestionDecomposer::MAX_SUBQUESTIONS, count($multi));

        $simple = $decomposer->decompose('Perché Penelope non riconosce subito Odisseo?');
        $this->assertCount(1, $simple);
        $this->assertSame('SQ1', $simple[0]['key']);

        $english = $decomposer->decompose('What car does Gustav drive and who takes his identity?');
        $this->assertCount(2, $english);
    }

    public function test_language_detector_follows_question_language(): void
    {
        $detector = new ResponseLanguageDetector;

        $this->assertSame('it', $detector->detect('Perché Penelope non riconosce subito Odisseo?'));
        $this->assertSame('en', $detector->detect('Why does the windmill fall in the book?'));
        $this->assertSame('it', $detector->detect('??')); // ambiguous → product language
    }

    public function test_atoms_partition_units_with_exact_absolute_coordinates(): void
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Prove 🎻 unicode', 'heading_path' => ['Prove 🎻 unicode']],
                ['text' => 'La prima frase parla del 𝄞 e finisce qui. La seconda frase è quella decisiva per la tesi. La terza chiude senza aggiungere nulla.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);

        $unitizer = new EvidenceUnitizer((int) config('mnemosyne.answers.evidence.unit_max_chars'));
        $checkedAtoms = 0;

        foreach (RetrievalChunk::query()->with(['spans', 'asset'])->get() as $chunk) {
            foreach ($unitizer->unitsForChunk($chunk, []) as $unit) {
                $this->assertNotEmpty($unit->atoms);
                $rebuilt = '';

                foreach ($unit->atoms as $atom) {
                    $checkedAtoms++;
                    // Exact in BOTH coordinate systems against canonical.
                    $this->assertSame(
                        mb_substr($built['canonical'], $atom['canonical_start'], $atom['canonical_end'] - $atom['canonical_start']),
                        $atom['text'],
                    );
                    $this->assertSame(
                        $this->utf16Slice($built['canonical'], $atom['utf16_start'], $atom['utf16_end']),
                        $atom['text'],
                    );
                    $this->assertSame(
                        Chunker::utf16Length($atom['text']),
                        $atom['utf16_end'] - $atom['utf16_start'],
                    );
                    $rebuilt .= $atom['text'];
                }

                // Atoms partition the unit text losslessly (whitespace
                // between sentences stays attached).
                $this->assertSame($unit->text, $rebuilt);
            }
        }

        $this->assertGreaterThan(3, $checkedAtoms);
    }

    /** Compound corpus: the car is in the book, the identity is not. */
    private function compoundBook(User $user): array
    {
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'La costa', 'heading_path' => ['La costa']],
                ['text' => 'Selene guidava una vecchia Daimler grigia lungo la strada della costa.'],
                ['text' => 'Ogni domenica lucidava la vecchia Daimler davanti al cancello della villa.'],
                ['text' => 'Qualcuno chiedeva sempre: chi osservava la scena dal muretto?'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        return $built;
    }

    public function test_compound_question_yields_honest_partial_answer_with_focused_expansion(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->compoundBook($user);

        // SQ1 has literal evidence ("vecchia Daimler"); SQ2 (identity)
        // has none anywhere in the book.
        $run = $this->makeRun($user, 'La vecchia Daimler e chi prende la sua identità nel libro?', [$built['asset']->id]);

        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Selene guidava una vecchia Daimler grigia.', ['E2'], 'textual_fact', 'SQ1'),
        ], 'partially_answered'));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2.S1'], 'DIRECTLY_STATED'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);
        $this->assertSame(AnswerOutcome::PartiallyAnswered, $run->outcome);

        // Decomposition persisted with per-subquestion coverage.
        $this->assertSame('question-decomposer 1.0.0', $run->question_decomposer_version);
        $subquestions = collect($run->subquestions);
        $this->assertCount(2, $subquestions);
        $this->assertSame('answered', $subquestions->firstWhere('key', 'SQ1')['status']);
        $this->assertSame('unanswered', $subquestions->firstWhere('key', 'SQ2')['status']);

        // The single bounded expansion was FOCUSED on the deficient
        // subquestion, and audited.
        $this->assertSame(1, $run->retrieval_expansion_count);
        $this->assertSame('SQ2', $run->retrieval_diagnostics['expansion_target']);

        // No fabricated identity claim exists anywhere.
        $this->assertCount(1, $run->claims);
        $this->assertSame('SQ1', $run->claims->first()->subquestion_key);

        // API presentation exposes the unanswered part + language +
        // duration.
        $presented = app(AnswerPresenter::class)->present($run);
        $this->assertSame('partially_answered', $presented['outcome']);
        $this->assertSame('it', $presented['response_language']);
        $this->assertIsInt($presented['duration_ms']);
        $unanswered = array_filter($presented['subquestions'], fn ($sq) => $sq['status'] === 'unanswered');
        $this->assertCount(1, $unanswered);
    }

    public function test_generator_receives_language_and_subquestions(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->compoundBook($user);

        $run = $this->makeRun($user, 'La vecchia Daimler e chi osservava la scena dal muretto?', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Selene guidava la Daimler.', ['E2'], 'textual_fact', 'SQ1'),
            $this->claim('CL2', 'Qualcuno osservava la scena dal muretto.', ['E4'], 'textual_fact', 'SQ2'),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2.S1']));
        $verifier->scriptFor('CL2', $this->verdict('CL2', 'direct', ['E4.S1']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $call = $generator->calls[0];
        $this->assertSame('Italian', $call['language']);
        $this->assertCount(2, $call['subquestions']);
        $this->assertSame('it', $run->response_language);
    }

    public function test_citation_spans_are_minimal_and_reader_highlights_only_them(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();

        // One unit with three sentences; only the SECOND supports the
        // claim.
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'Il registro', 'heading_path' => ['Il registro']],
                ['text' => 'La mattina il registro restava chiuso. Il custode annotava ogni nave al tramonto. La sera nessuno entrava nella sala.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        $run = $this->makeRun($user, 'Il custode annotava', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il custode annotava le navi al tramonto.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2.S2'], 'DIRECTLY_STATED'));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);

        // The persisted CitationSpan is exactly the second sentence.
        $claim = $run->claims->first();
        $pivotAtoms = json_decode($claim->evidence->first()->pivot->atoms, true);
        $this->assertCount(1, $pivotAtoms);
        $spanText = mb_substr(
            $built['canonical'],
            $pivotAtoms[0]['canonical_start'],
            $pivotAtoms[0]['canonical_end'] - $pivotAtoms[0]['canonical_start'],
        );
        $this->assertStringContainsString('Il custode annotava ogni nave al tramonto.', $spanText);
        $this->assertStringNotContainsString('La mattina', $spanText);
        $this->assertStringNotContainsString('nessuno entrava', $spanText);

        // API citation exposes the span.
        $presented = app(AnswerPresenter::class)->present($run);
        $this->assertNotEmpty($presented['citations'][0]['spans']);

        // Reader highlights ONLY the minimal span, not the whole unit.
        $evidence = $run->evidence->firstWhere('citation_number', 1);
        $response = $this->actingAs($user)->get(
            '/library/books/'.$built['asset']->public_id."/reader?answer={$run->public_id}&evidence={$evidence->evidence_key}",
        );
        $response->assertOk();
        $highlights = $response->viewData('page')['props']['highlights'];
        $this->assertCount(1, $highlights);

        $nodes = $response->viewData('page')['props']['current_section']['nodes'];
        $node = collect($nodes)->firstWhere('id', $evidence->source_node_id);
        $highlighted = $this->utf16Slice($node['text'], $highlights[0]['utf16_start'], $highlights[0]['utf16_end']);
        $this->assertStringContainsString('Il custode annotava ogni nave al tramonto.', $highlighted);
        $this->assertStringNotContainsString('La mattina', $highlighted);
        $this->assertLessThan(mb_strlen($evidence->excerpt), mb_strlen($highlighted), 'span must be narrower than the unit');
    }
}
