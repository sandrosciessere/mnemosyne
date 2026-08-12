<?php

namespace Tests\Feature\Answers;

use App\Enums\AnswerRunStatus;
use App\Models\User;
use App\Services\Answers\AnswerPresenter;
use App\Services\Answers\GroundedAnswerOrchestrator;
use App\Services\Answers\Providers\AnswerPromptBuilder;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class CrossLanguageAndContextTest extends TestCase
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

    public function test_italian_question_over_english_source_keeps_source_language_citations(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();

        // English-language source book.
        $built = $this->buildArtifacts([
            0 => [
                ['type' => 'heading', 'text' => 'The Windmill Ledger', 'heading_path' => ['The Windmill Ledger']],
                ['text' => 'The windmill was rebuilt three times, and each time the animals worked through the winter storms.'],
                ['text' => 'Nobody ever questioned who had destroyed the windmill the second time.'],
            ],
        ]);
        $generation = $this->makeTestGeneration('active');
        app(RetrievalIndexer::class)->indexAsset($generation, $built['asset']);
        $this->grant($built['asset'], $user);

        // Italian question whose literal matches the English source
        // (exact component carries the sqlite path; PG integration
        // covers dense cross-language recall with the real model).
        $run = $this->makeRun($user, 'Chi ricostruì il "the windmill" e quante volte?', [$built['asset']->id]);

        // Fake generator answers IN ITALIAN citing the ENGLISH unit.
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il mulino a vento fu ricostruito tre volte.', ['E2']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E2']));

        app(GroundedAnswerOrchestrator::class)->execute($run);
        $run->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run->status);

        $presented = app(AnswerPresenter::class)->present($run);
        // Claim in the question's language…
        $this->assertStringContainsString('mulino', $presented['claims'][0]['text']);
        // …citation excerpt remains the EXACT original-language source,
        // never a translation.
        $citation = $presented['citations'][0];
        $this->assertStringContainsString('windmill', $citation['excerpt']);
        $this->assertSame(
            mb_substr($built['canonical'], $citation['canonical_start'], $citation['canonical_end'] - $citation['canonical_start']),
            $citation['excerpt'],
        );
    }

    public function test_conversation_context_contains_only_previous_user_questions(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        // First turn.
        $run1 = $this->makeRun($user, 'pescatori del borgo', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'PROSA_ASSISTENTE_UNICA: i pescatori rientravano sani e salvi.', ['E1']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1']));
        app(GroundedAnswerOrchestrator::class)->execute($run1);
        $run1->refresh();
        $this->assertSame(AnswerRunStatus::Ready, $run1->status);

        // Follow-up in the same conversation.
        $run2 = $this->makeRun($user, 'medaglia di bronzo', [$built['asset']->id], $run1->conversation);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Marta ricevette una medaglia di bronzo.', ['E1']),
        ]));
        $verifier->scriptFor('*', $this->verdict('CL1', 'direct', ['E1']));
        app(GroundedAnswerOrchestrator::class)->execute($run2);
        $run2->refresh();
        $this->assertSame(AnswerRunStatus::Ready, $run2->status);

        // The generator's second call received the previous USER
        // question as referential context — and NEVER any assistant
        // prose (previous model output cannot become evidence).
        $secondCall = $generator->calls[1];
        $this->assertStringContainsString('pescatori del borgo', (string) $secondCall['context']);
        $this->assertStringNotContainsString('PROSA_ASSISTENTE_UNICA', (string) $secondCall['context']);

        // And the prompt layer explicitly marks context as non-evidence.
        $contextBlock = (new AnswerPromptBuilder)
            ->contextBlock($secondCall['context']);
        $this->assertStringContainsString('NEVER evidence', $contextBlock);
    }

    public function test_scope_change_between_turns_uses_only_new_scope_evidence(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $corpus = $this->comparativeCorpus($user);

        // Turn 1: scope = book A only.
        $run1 = $this->makeRun($user, 'faro', [$corpus['a']['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Il guardiano curava il faro nord.', ['E1']),
        ]));
        $verifier->scriptFor('*', $this->verdict('CL1', 'direct', ['E1']));
        app(GroundedAnswerOrchestrator::class)->execute($run1);
        $run1->refresh();

        // Turn 2, same conversation, scope CHANGED to book B only.
        $run2 = $this->makeRun($user, 'faro', [$corpus['b']['asset']->id], $run1->conversation);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'Nella locanda una lampada era chiamata il piccolo faro.', ['E1']),
        ]));
        app(GroundedAnswerOrchestrator::class)->execute($run2);
        $run2->refresh();

        $this->assertSame(AnswerRunStatus::Ready, $run2->status);

        // Every evidence unit of turn 2 comes from book B — evidence
        // from the no-longer-selected book A is never reused.
        $assetIds = $run2->evidence->pluck('book_asset_id')->unique()->all();
        $this->assertSame([$corpus['b']['asset']->id], $assetIds);

        // Scope persistence reflects the per-answer scope.
        $this->assertSame([$corpus['a']['asset']->id], $run1->scopeAssets->pluck('id')->all());
        $this->assertSame([$corpus['b']['asset']->id], $run2->scopeAssets->pluck('id')->all());
    }
}
