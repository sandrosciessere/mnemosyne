<?php

namespace Tests\Feature\Api;

use App\Jobs\RunIngestionStageJob;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/submissions')->assertUnauthorized();
        $this->postJson('/api/v1/submissions')->assertUnauthorized();
    }

    public function test_user_can_submit_and_read_via_api(): void
    {
        $user = User::factory()->create();

        $create = $this->actingAs($user)->post('/api/v1/submissions', [
            'epub' => UploadedFile::fake()->createWithContent('api-book.epub', 'PK-api-bytes'),
            'note' => 'via API',
        ], ['Accept' => 'application/json']);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.original_filename', 'api-book.epub')
            ->assertJsonPath('data.note', 'via API');

        $publicId = $create->json('data.id');
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $publicId);

        $this->actingAs($user)->getJson('/api/v1/submissions/'.$publicId)
            ->assertOk()
            ->assertJsonPath('data.id', $publicId);

        $list = $this->actingAs($user)->getJson('/api/v1/submissions');
        $list->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayHasKey('next_cursor', $list->json('meta'));
    }

    public function test_negative_per_page_cannot_disable_the_row_limit(): void
    {
        $user = User::factory()->create();
        BookSubmission::factory()->count(5)->create(['user_id' => $user->id]);

        // A negative per_page must never bypass the limit into an unbounded
        // full-table load; the clamp floors it to a positive page size.
        $list = $this->actingAs($user)->getJson('/api/v1/submissions?per_page=-1');
        $list->assertOk();
        $this->assertLessThanOrEqual(1, count($list->json('data')));
    }

    public function test_user_cannot_read_others_submission(): void
    {
        $stranger = User::factory()->create();
        $submission = BookSubmission::factory()->create();

        $this->actingAs($stranger)
            ->getJson('/api/v1/submissions/'.$submission->public_id)
            ->assertForbidden();
    }

    public function test_admin_endpoints_reject_non_admin(): void
    {
        $user = User::factory()->create();
        $submission = BookSubmission::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/admin/submissions')->assertForbidden();
        $this->actingAs($user)
            ->postJson('/api/v1/admin/submissions/'.$submission->public_id.'/approve')
            ->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/admin/ingestion-runs')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/admin/processing/overview')->assertForbidden();
    }

    public function test_admin_can_approve_and_manage_runs_via_api(): void
    {
        $admin = User::factory()->admin()->create();
        $submission = BookSubmission::factory()->create();

        $approve = $this->actingAs($admin)
            ->postJson('/api/v1/admin/submissions/'.$submission->public_id.'/approve');

        $approve->assertOk()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(RunIngestionStageJob::class);

        $runId = $approve->json('data.run.id');

        $this->actingAs($admin)->getJson('/api/v1/admin/ingestion-runs/'.$runId)
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.pipeline_version', '1');

        $this->actingAs($admin)
            ->patchJson('/api/v1/admin/ingestion-runs/'.$runId.'/priority', ['priority' => 'high'])
            ->assertOk()
            ->assertJsonPath('data.priority', 'high');

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/ingestion-runs/'.$runId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.cancel_requested', true);

        $overview = $this->actingAs($admin)->getJson('/api/v1/admin/processing/overview');
        $overview->assertOk();
        $this->assertSame(1, $overview->json('data.runs.queued'));
        $this->assertSame('1', $overview->json('data.pipeline_version'));
    }

    public function test_approving_already_approved_returns_conflict_error_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $submission = BookSubmission::factory()->approved()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/submissions/'.$submission->public_id.'/approve')
            ->assertStatus(409)
            ->assertJsonStructure(['error' => ['code', 'message', 'details']])
            ->assertJsonPath('error.code', 'SUBMISSION_NOT_PENDING');
    }

    public function test_retry_of_non_retryable_run_returns_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $run = IngestionRun::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/ingestion-runs/'.$run->public_id.'/retry')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RUN_NOT_RETRYABLE');
    }
}
