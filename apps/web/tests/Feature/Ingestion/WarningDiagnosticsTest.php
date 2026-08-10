<?php

namespace Tests\Feature\Ingestion;

use App\Models\BookSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithFakeWorker;
use Tests\TestCase;

/**
 * Manual-test findings: warnings must be visible and aggregated (one
 * unique issue per code, not one per stage), with actionable context
 * (affected documents), and the pipeline view must never claim stages
 * ran when they did not (exact duplicates reuse, they don't re-execute).
 */
class WarningDiagnosticsTest extends TestCase
{
    use InteractsWithFakeWorker;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config([
            'mnemosyne.data_path' => Storage::disk('data')->path(''),
            'mnemosyne.worker.internal_token' => 'test-token',
            'mnemosyne.ingestion.queue_connection' => 'database',
            'mnemosyne.ingestion.retry.backoff_seconds' => [0, 0, 0],
        ]);
        $this->admin = User::factory()->admin()->create();
    }

    /** Worker fake mimicking the real "Odissea" case: font obfuscation
     *  reported by every stage, image-only content by normalize. */
    private function fakeObfuscatedWorker(): void
    {
        Http::fake(function ($request) {
            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            $fontWarning = [
                'code' => 'FONT_OBFUSCATION',
                'severity' => 'warning',
                'message' => 'EPUB uses font obfuscation only (not DRM)',
                'overrideable' => true,
                'details' => ['uris' => ['fonts/serif.otf']],
            ];

            $issues = [$fontWarning];

            if ($stage === 'normalize') {
                $issues[] = [
                    'code' => 'IMAGE_ONLY_CONTENT',
                    'severity' => 'warning',
                    'message' => 'spine documents contain images with little or no extractable text',
                    'overrideable' => true,
                    'details' => [
                        'hrefs' => ['OEBPS/cover.xhtml', 'OEBPS/frontispiece.xhtml'],
                        'book_level' => false,
                    ],
                ];
            }

            $envelope = $this->happyEnvelopeFor($stage);
            $envelope['issues'] = $issues;
            $envelope['status'] = 'passed_with_warnings';

            return Http::response($envelope);
        });
    }

    private function submitAndDrain(string $content = 'PK-warn-bytes'): BookSubmission
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/library/submissions', [
            'epub' => UploadedFile::fake()->createWithContent(uniqid().'.epub', $content),
        ]);
        $submission = BookSubmission::query()->latest('id')->first();
        $this->actingAs($this->admin)->post('/admin/submissions/'.$submission->public_id.'/approve');
        $this->drainQueue();

        return $submission->refresh();
    }

    public function test_repeated_warning_is_aggregated_into_one_issue_with_stage_list(): void
    {
        $this->fakeObfuscatedWorker();
        $submission = $this->submitAndDrain();
        $run = $submission->latestRun;

        $this->assertSame('ready_for_enrichment_with_warnings', $run->asset->ingestion_status->value);

        $response = $this->actingAs($this->admin)->get('/admin/processing/runs/'.$run->public_id);
        $summary = collect($response->viewData('page')['props']['warnings_summary']);

        // Two UNIQUE issues — not five noisy rows.
        $this->assertCount(2, $summary);

        $font = $summary->firstWhere('code', 'FONT_OBFUSCATION');
        $this->assertSame(4, $font['occurrences']);
        $this->assertSame(['validate', 'parse', 'normalize', 'structure'], $font['stages']);
        $this->assertSame('EPUB uses font obfuscation only (not DRM)', $font['message']);
        $this->assertSame(['fonts/serif.otf'], $font['details']['uris']);

        $imageOnly = $summary->firstWhere('code', 'IMAGE_ONLY_CONTENT');
        $this->assertSame(1, $imageOnly['occurrences']);
        $this->assertSame(['normalize'], $imageOnly['stages']);
        // Actionable context: exactly WHICH documents are affected.
        $this->assertSame(
            ['OEBPS/cover.xhtml', 'OEBPS/frontispiece.xhtml'],
            $imageOnly['details']['hrefs'],
        );

        // Raw auditability preserved: all four stage-level events remain.
        $this->assertSame(
            4,
            $run->events()->where('type', 'stage.warning')
                ->where('payload->code', 'FONT_OBFUSCATION')->count(),
        );
    }

    public function test_asset_page_shows_warning_summary(): void
    {
        $this->fakeObfuscatedWorker();
        $submission = $this->submitAndDrain();
        $asset = $submission->latestRun->asset;

        $response = $this->actingAs($this->admin)->get('/admin/library/assets/'.$asset->public_id);
        $props = $response->viewData('page')['props'];

        $summary = collect($props['warnings_summary']);
        $this->assertCount(2, $summary);
        $this->assertNotNull($summary->firstWhere('code', 'IMAGE_ONLY_CONTENT'));
        $this->assertSame(
            'ready_for_enrichment_with_warnings',
            $props['asset']['ingestion_status'],
        );
    }

    public function test_duplicate_run_does_not_mask_producer_warnings_on_asset(): void
    {
        // Real manual-test regression (canone-inverso): first upload
        // completes WITH warnings, then an exact duplicate is uploaded.
        // The duplicate's hash-only succeeded run must not blank the
        // asset's warning summary.
        $this->fakeObfuscatedWorker();
        $first = $this->submitAndDrain('dup-mask-bytes');
        $this->submitAndDrain('dup-mask-bytes');

        $asset = $first->refresh()->asset;
        $this->assertSame(2, $asset->runs()->where('status', 'succeeded')->count());

        $response = $this->actingAs($this->admin)->get('/admin/library/assets/'.$asset->public_id);
        $summary = collect($response->viewData('page')['props']['warnings_summary']);

        $this->assertCount(2, $summary);
        $this->assertSame(4, $summary->firstWhere('code', 'FONT_OBFUSCATION')['occurrences']);
    }

    public function test_clean_asset_has_empty_warning_summary(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->submitAndDrain();

        $response = $this->actingAs($this->admin)
            ->get('/admin/library/assets/'.$submission->latestRun->asset->public_id);

        $this->assertSame([], $response->viewData('page')['props']['warnings_summary']);
    }

    public function test_exact_duplicate_pipeline_marks_unexecuted_stages_as_reused(): void
    {
        $this->fakeHappyWorker();
        $this->submitAndDrain('identical-bytes');
        $second = $this->submitAndDrain('identical-bytes');

        $run = $second->latestRun;
        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame(['hash'], $run->attempts->pluck('stage.value')->all());

        $response = $this->actingAs($this->admin)->get('/admin/processing/runs/'.$run->public_id);
        $props = $response->viewData('page')['props'];

        $stages = collect($props['pipeline_stages'])->pluck('execution_status', 'stage');
        $this->assertSame('succeeded', $stages['hash']);
        foreach (['validate', 'parse', 'normalize', 'structure'] as $stage) {
            $this->assertSame('reused', $stages[$stage], "stage {$stage} must be reused, not done");
        }

        // The duplicate explanation links the reused asset.
        $this->assertTrue($props['submission']['is_exact_duplicate']);
        $this->assertNotNull($props['duplicate']);
        $this->assertSame(
            $run->asset->public_id,
            $props['duplicate']['reused_asset']['public_id'],
        );
        $this->assertSame('ready', $props['duplicate']['disposition']);
    }

    public function test_non_duplicate_succeeded_run_reports_all_stages_succeeded(): void
    {
        $this->fakeHappyWorker();
        $submission = $this->submitAndDrain();

        $response = $this->actingAs($this->admin)
            ->get('/admin/processing/runs/'.$submission->latestRun->public_id);
        $props = $response->viewData('page')['props'];

        $statuses = collect($props['pipeline_stages'])->pluck('execution_status')->unique()->all();
        $this->assertSame(['succeeded'], $statuses);
        $this->assertNull($props['duplicate']);
        $this->assertContainsOnly(
            'int',
            collect($props['pipeline_stages'])->pluck('duration_ms')->all(),
        );
    }

    public function test_failed_run_marks_later_stages_not_executed(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failed', 'stage' => 'validate', 'handler_version' => '1.1.0',
            'duration_ms' => 1,
            'issues' => [['code' => 'ZIP_INVALID', 'severity' => 'hard_block', 'message' => 'not a zip', 'overrideable' => false]],
            'result' => null,
        ])]);

        $submission = $this->submitAndDrain();
        $run = $submission->latestRun;
        $this->assertSame('failed', $run->status->value);

        $response = $this->actingAs($this->admin)->get('/admin/processing/runs/'.$run->public_id);
        $stages = collect($response->viewData('page')['props']['pipeline_stages'])
            ->pluck('execution_status', 'stage');

        $this->assertSame('succeeded', $stages['hash']);
        $this->assertSame('failed', $stages['validate']);
        foreach (['parse', 'normalize', 'structure'] as $stage) {
            $this->assertSame('not_executed', $stages[$stage]);
        }
    }

    public function test_api_run_detail_exposes_derived_presentation(): void
    {
        $this->fakeObfuscatedWorker();
        $first = $this->submitAndDrain('api-dup-bytes');

        $this->fakeHappyWorker();
        $second = $this->submitAndDrain('api-dup-bytes');

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/ingestion-runs/'.$second->latestRun->public_id);

        $response->assertOk()
            ->assertJsonPath('data.pipeline_stages.0.execution_status', 'succeeded')
            ->assertJsonPath('data.pipeline_stages.1.execution_status', 'reused')
            ->assertJsonPath('data.duplicate.reused_asset.public_id', $second->latestRun->asset->public_id);

        $first->refresh();
        $summary = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/ingestion-runs/'.$first->latestRun->public_id)
            ->json('data.warnings_summary');

        $this->assertSame(
            4,
            collect($summary)->firstWhere('code', 'FONT_OBFUSCATION')['occurrences'],
        );
    }
}
