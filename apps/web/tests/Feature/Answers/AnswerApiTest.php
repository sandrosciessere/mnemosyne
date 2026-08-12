<?php

namespace Tests\Feature\Answers;

use App\Enums\AnswerRunStatus;
use App\Jobs\GenerateGroundedAnswerJob;
use App\Models\GroundedAnswerRun;
use App\Models\User;
use App\Services\Answers\GroundedAnswerOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAnswerFixtures;
use Tests\TestCase;

class AnswerApiTest extends TestCase
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

    public function test_submission_returns_202_with_queued_status_and_polling_url(): void
    {
        $this->useFakeProviders();
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $response = $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
            'scope' => ['book_asset_ids' => [$built['asset']->public_id]],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['id', 'status', 'conversation_id', 'url']]);

        $run = GroundedAnswerRun::query()->where('public_id', $response->json('data.id'))->first();
        $this->assertNotNull($run);
        $this->assertSame(AnswerRunStatus::Queued, $run->status);
        $this->assertNotNull($run->retrieval_generation_id, 'generation snapshotted at submission');
        $this->assertSame([$built['asset']->id], $run->scopeAssets->pluck('id')->all());

        Queue::assertPushed(GenerateGroundedAnswerJob::class);

        // Polling while queued returns the persisted state.
        $this->actingAs($user)->getJson('/api/v1/answers/'.$run->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.claims', []);
    }

    public function test_validation_bounds_question_and_scope(): void
    {
        $this->useFakeProviders();
        $user = User::factory()->create();
        $this->lighthouseBook($user);

        $this->postJson('/api/v1/answers', ['question' => 'anon'])->assertUnauthorized();

        $this->actingAs($user)->postJson('/api/v1/answers', ['question' => 'ab'])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/answers', ['question' => str_repeat('q', 2001)])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'domanda valida',
            'scope' => ['book_asset_ids' => ['not-a-ulid']],
        ])->assertStatus(422);
    }

    public function test_scope_acl_fails_closed_for_unknown_and_unauthorized_books(): void
    {
        $this->useFakeProviders();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $built = $this->lighthouseBook($owner);

        // Authorized ULID + unknown ULID → indistinguishable 403.
        $this->actingAs($owner)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
            'scope' => ['book_asset_ids' => [$built['asset']->public_id, '01aaaaaaaaaaaaaaaaaaaaaaaa']],
        ])->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_NOT_ACCESSIBLE');

        // Possessing a real ULID without a grant → same 403.
        $this->actingAs($stranger)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
            'scope' => ['book_asset_ids' => [$built['asset']->public_id]],
        ])->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_NOT_ACCESSIBLE');

        // No grants at all + no explicit scope → nothing to search.
        $this->actingAs($stranger)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
        ])->assertStatus(422)->assertJsonPath('error.code', 'SCOPE_EMPTY');
    }

    public function test_answers_and_evidence_are_owner_or_admin_only(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $built = $this->lighthouseBook($owner);

        $run = $this->makeRun($owner, 'pescatori del borgo', [$built['asset']->id]);
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'I pescatori rientravano sani e salvi.', ['E1']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1']));
        app(GroundedAnswerOrchestrator::class)->execute($run);

        // Owner reads the answer and its evidence.
        $this->actingAs($owner)->getJson('/api/v1/answers/'.$run->public_id)
            ->assertOk()->assertJsonPath('data.outcome', 'answered');
        $this->actingAs($owner)->getJson('/api/v1/answers/'.$run->public_id.'/evidence/E1')
            ->assertOk()->assertJsonPath('data.evidence_key', 'E1');

        // Another user: guessed answer id → 403, no snippet leakage.
        $foreign = $this->actingAs($other)->getJson('/api/v1/answers/'.$run->public_id);
        $foreign->assertStatus(403)->assertJsonPath('error.code', 'ANSWER_NOT_ACCESSIBLE');
        $this->assertStringNotContainsString('pescatori', $foreign->getContent());
        $this->actingAs($other)->getJson('/api/v1/answers/'.$run->public_id.'/evidence/E1')
            ->assertStatus(403);

        // Admin can audit.
        $this->actingAs($admin)->getJson('/api/v1/answers/'.$run->public_id)->assertOk();

        // Diagnostics only for admin with debug flag.
        $this->actingAs($owner)->getJson('/api/v1/answers/'.$run->public_id.'?debug=1')
            ->assertOk()->assertJsonMissingPath('data.diagnostics');
        $this->actingAs($admin)->getJson('/api/v1/answers/'.$run->public_id.'?debug=1')
            ->assertOk()->assertJsonPath('data.diagnostics.generator.provider', 'fake');
    }

    public function test_active_run_cap_returns_429(): void
    {
        $this->useFakeProviders();
        config(['mnemosyne.answers.max_active_runs_per_user' => 1]);
        $user = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
        ])->assertStatus(202);

        $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'seconda domanda contemporanea',
        ])->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ACTIVE_ANSWERS');
    }

    public function test_no_active_generation_is_409(): void
    {
        $this->useFakeProviders();
        $user = User::factory()->create();
        // Build artifacts but with a BUILDING generation only.
        $this->lighthouseBook($user, $this->makeTestGeneration('building'));

        $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
        ])->assertStatus(409)->assertJsonPath('error.code', 'RETRIEVAL_INACTIVE');
    }

    public function test_conversation_flow_and_foreign_conversation_acl(): void
    {
        [$generator, $verifier] = $this->useFakeProviders();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $built = $this->lighthouseBook($user);

        $first = $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'pescatori del borgo',
        ])->assertStatus(202);
        $conversationId = $first->json('data.conversation_id');
        $this->assertNotNull($conversationId);

        $run = GroundedAnswerRun::query()->where('public_id', $first->json('data.id'))->first();
        $generator->scriptOutput($this->generatorAnswer([
            $this->claim('CL1', 'I pescatori rientravano sani e salvi.', ['E1']),
        ]));
        $verifier->scriptFor('CL1', $this->verdict('CL1', 'direct', ['E1']));
        app(GroundedAnswerOrchestrator::class)->execute($run);

        // Follow-up in the same conversation.
        $this->actingAs($user)->postJson('/api/v1/answers', [
            'question' => 'medaglia di bronzo',
            'conversation_id' => $conversationId,
        ])->assertStatus(202)->assertJsonPath('data.conversation_id', $conversationId);

        // Conversation transcript: user turns + assistant turn with the
        // embedded verified answer.
        $show = $this->actingAs($user)->getJson('/api/v1/conversations/'.$conversationId)->assertOk();
        $roles = array_column($show->json('data.messages'), 'role');
        $this->assertSame(['user', 'assistant', 'user'], $roles);
        $this->assertSame('answered', $show->json('data.messages.1.answer.outcome'));

        // Foreign conversation: listing excludes it, direct access 403,
        // and submitting INTO it is rejected.
        $this->actingAs($other)->getJson('/api/v1/conversations')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson('/api/v1/conversations/'.$conversationId)
            ->assertStatus(403);
        $this->lighthouseBook($other, $built['generation']);
        $this->actingAs($other)->postJson('/api/v1/answers', [
            'question' => 'domanda intrusa nel thread altrui',
            'conversation_id' => $conversationId,
        ])->assertStatus(403)->assertJsonPath('error.code', 'CONVERSATION_NOT_ACCESSIBLE');
    }
}
