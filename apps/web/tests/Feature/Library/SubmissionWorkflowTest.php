<?php

namespace Tests\Feature\Library;

use App\Enums\SubmissionStatus;
use App\Jobs\RunIngestionStageJob;
use App\Models\BookSubmission;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();
    }

    private function epubUpload(string $name = 'book.epub'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'PK'.str_repeat('x', 200));
    }

    public function test_guest_cannot_submit(): void
    {
        $this->post('/library/submissions', ['epub' => $this->epubUpload()])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('book_submissions', 0);
    }

    public function test_user_can_submit_an_epub(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/library/submissions', [
            'epub' => $this->epubUpload('my great book.epub'),
            'note' => 'First edition scan.',
        ]);

        $submission = BookSubmission::query()->sole();
        $response->assertRedirect('/library/submissions/'.$submission->public_id);

        $this->assertSame(SubmissionStatus::PendingApproval, $submission->status);
        $this->assertSame($user->id, $submission->user_id);
        $this->assertSame('my great book.epub', $submission->original_filename);
        $this->assertSame('First edition scan.', $submission->note);
        Storage::disk('data')->assertExists($submission->incoming_path);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'submission.created']);
        Queue::assertNothingPushed(); // No auto-approval by default.
    }

    public function test_upload_rejects_non_epub_extension(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent('malware.exe', 'MZ'),
        ])->assertSessionHasErrors('epub');
    }

    public function test_client_payload_cannot_touch_protected_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/library/submissions', [
            'epub' => $this->epubUpload(),
            'status' => 'approved',
            'priority' => 'high',
            'book_asset_id' => 123,
        ]);

        $submission = BookSubmission::query()->sole();
        $this->assertSame(SubmissionStatus::PendingApproval, $submission->status);
        $this->assertSame('normal', $submission->priority->value);
        $this->assertNull($submission->book_asset_id);
    }

    public function test_admin_approval_queues_ingestion(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/library/submissions', ['epub' => $this->epubUpload()]);
        $submission = BookSubmission::query()->sole();

        $this->actingAs($admin)
            ->post('/admin/submissions/'.$submission->public_id.'/approve')
            ->assertRedirect();

        $submission->refresh();
        $this->assertSame(SubmissionStatus::Approved, $submission->status);
        $this->assertSame($admin->id, $submission->approved_by);
        $this->assertNotNull($submission->latestRun);
        $this->assertSame('queued', $submission->latestRun->status->value);

        Queue::assertPushed(RunIngestionStageJob::class, function (RunIngestionStageJob $job) use ($submission) {
            return $job->runId === $submission->latestRun->id
                && $job->stage === 'hash'
                && $job->queue === 'ingestion-normal';
        });

        $this->assertDatabaseHas('ingestion_events', ['type' => 'submission.approved']);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'run.queued']);
    }

    public function test_non_admin_cannot_approve_or_reject(): void
    {
        $user = User::factory()->create();
        $submission = BookSubmission::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post('/admin/submissions/'.$submission->public_id.'/approve')
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/admin/submissions/'.$submission->public_id.'/reject', ['reason' => 'nope'])
            ->assertForbidden();

        $this->assertSame(SubmissionStatus::PendingApproval, $submission->refresh()->status);
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $submission = BookSubmission::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/submissions/'.$submission->public_id.'/reject', [
                'reason' => 'Not a valid EPUB source.',
            ])
            ->assertRedirect();

        $submission->refresh();
        $this->assertSame(SubmissionStatus::Rejected, $submission->status);
        $this->assertSame('Not a valid EPUB source.', $submission->rejection_reason);
        $this->assertSame($admin->id, $submission->rejected_by);
        Queue::assertNothingPushed();
    }

    public function test_approving_twice_fails_cleanly(): void
    {
        $admin = User::factory()->admin()->create();
        $submission = BookSubmission::factory()->create();

        $this->actingAs($admin)->post('/admin/submissions/'.$submission->public_id.'/approve');
        $this->actingAs($admin)
            ->post('/admin/submissions/'.$submission->public_id.'/approve')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, $submission->runs()->count());
    }

    public function test_auto_approval_when_enabled(): void
    {
        SystemSetting::set(SystemSetting::SUBMISSION_AUTO_APPROVAL, true);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/library/submissions', ['epub' => $this->epubUpload()]);

        $submission = BookSubmission::query()->sole();
        $this->assertSame(SubmissionStatus::Approved, $submission->status);
        $this->assertNull($submission->approved_by);
        $this->assertDatabaseHas('ingestion_events', ['type' => 'submission.auto_approved']);
        Queue::assertPushed(RunIngestionStageJob::class);
    }

    public function test_owner_can_cancel_pending_submission(): void
    {
        $user = User::factory()->create();
        $submission = BookSubmission::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->post('/library/submissions/'.$submission->public_id.'/cancel')
            ->assertRedirect();

        $this->assertSame(SubmissionStatus::Cancelled, $submission->refresh()->status);
    }

    public function test_user_cannot_cancel_others_submissions(): void
    {
        $stranger = User::factory()->create();
        $submission = BookSubmission::factory()->create();

        $this->actingAs($stranger)
            ->post('/library/submissions/'.$submission->public_id.'/cancel')
            ->assertForbidden();
    }

    public function test_user_sees_only_own_submissions(): void
    {
        $user = User::factory()->create();
        BookSubmission::factory()->create(['user_id' => $user->id]);
        BookSubmission::factory()->count(2)->create();

        $response = $this->actingAs($user)->get('/library/submissions');

        $response->assertOk();
        $this->assertCount(1, $response->viewData('page')['props']['submissions']['data']);
    }

    public function test_user_cannot_view_others_submission_detail(): void
    {
        $stranger = User::factory()->create();
        $submission = BookSubmission::factory()->create();

        $this->actingAs($stranger)
            ->get('/library/submissions/'.$submission->public_id)
            ->assertForbidden();
    }

    public function test_admin_settings_toggle_requires_admin(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($user)
            ->put('/admin/system/settings', ['submission_auto_approval' => true])
            ->assertForbidden();

        $this->actingAs($admin)
            ->put('/admin/system/settings', ['submission_auto_approval' => true])
            ->assertRedirect();

        $this->assertTrue(SystemSetting::autoApprovalEnabled());
    }

    public function test_submission_rate_limit_applies(): void
    {
        config(['mnemosyne.ingestion.rate_limits.submissions_per_hour' => 2]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/library/submissions', ['epub' => $this->epubUpload('a.epub')])->assertRedirect();
        $this->actingAs($user)->post('/library/submissions', ['epub' => $this->epubUpload('b.epub')])->assertRedirect();
        $this->actingAs($user)->post('/library/submissions', ['epub' => $this->epubUpload('c.epub')])
            ->assertStatus(429);
    }
}
