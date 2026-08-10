<?php

namespace Tests\Feature\Library;

use App\Models\BookSubmission;
use App\Models\DiscoveryEntry;
use App\Models\DiscoveryRun;
use App\Services\Library\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ImportAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    private string $importRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();

        $this->importRoot = sys_get_temp_dir().'/mnemosyne-import-test-'.getmypid();
        File::deleteDirectory($this->importRoot);
        File::makeDirectory($this->importRoot, 0755, true);
        config(['mnemosyne.import_sources' => ['test-lib' => $this->importRoot]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->importRoot);
        parent::tearDown();
    }

    private function seedStructuredLibrary(): void
    {
        File::makeDirectory($this->importRoot.'/Ada Example/First Book', 0755, true);
        File::makeDirectory($this->importRoot.'/Ada Example/Second Book', 0755, true);
        File::makeDirectory($this->importRoot.'/Zoe Author/Third Book', 0755, true);
        File::put($this->importRoot.'/Ada Example/First Book/first.epub', 'epub-one');
        File::put($this->importRoot.'/Ada Example/Second Book/second.epub', 'epub-two');
        File::put($this->importRoot.'/Zoe Author/Third Book/third.epub', 'epub-three');
    }

    // ---- Fix 1: non-UTF-8 filenames are lossless & byte-exact -------------

    public function test_non_utf8_filenames_stay_distinct_and_are_reconstructed_at_import(): void
    {
        // Two latin-1 filenames that mb_substr would BOTH mangle to "caf?.epub".
        $nameE9 = "caf\xe9.epub"; // é in latin-1 — invalid standalone UTF-8
        $nameE8 = "caf\xe8.epub"; // è in latin-1 — invalid standalone UTF-8
        file_put_contents($this->importRoot.'/'.$nameE9, 'CAFE-E9');
        file_put_contents($this->importRoot.'/'.$nameE8, 'CAFE-E8');

        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        // TWO distinct entries — not collapsed by a lossy "?"-mangle.
        $this->assertSame(2, $run->entries()->count());

        $entryE9 = $run->entries()
            ->where('relative_path', DiscoveryEntry::encodeRelativePath($nameE9))->sole();
        $entryE8 = $run->entries()
            ->where('relative_path', DiscoveryEntry::encodeRelativePath($nameE8))->sole();
        $this->assertNotSame($entryE9->id, $entryE8->id);
        // display_path is valid UTF-8 for PostgreSQL/UI (bytes -> U+FFFD).
        $this->assertTrue(mb_check_encoding($entryE9->display_path, 'UTF-8'));

        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id])->assertSuccessful();

        // Import decoded each authoritative value back to the EXACT bytes,
        // found the right file, and copied the right content — proving the
        // reconstruction is byte-exact for both.
        $this->assertSame('CAFE-E9', Storage::disk('data')->get($entryE9->refresh()->submission->incoming_path));
        $this->assertSame('CAFE-E8', Storage::disk('data')->get($entryE8->refresh()->submission->incoming_path));
    }

    // ---- Fix 2: staging cleanup on failure + orphan reaper ----------------

    public function test_submission_failure_during_import_leaves_no_orphan_staging(): void
    {
        $this->seedStructuredLibrary();
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        // Force every submission creation to throw AFTER the file was staged.
        $this->mock(SubmissionService::class, function ($mock) {
            $mock->shouldReceive('createFromFilesystem')->andThrow(new RuntimeException('forced failure'));
        });

        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id])->assertSuccessful();

        // Every staging copy this run created was deleted on the failure path.
        $this->assertSame([], Storage::disk('data')->allFiles('library/incoming'));
        $this->assertSame([], Storage::disk('data')->directories('library/incoming'));
        $this->assertSame(0, BookSubmission::query()->count());
        $this->assertSame(3, $run->entries()->where('status', 'import_failed')->count());
    }

    public function test_cleanup_reaps_orphan_incoming_only_dry_run_vs_force(): void
    {
        $disk = Storage::disk('data');

        // A live submission still owning its incoming dir.
        $live = BookSubmission::factory()->filesystem()->create([
            'incoming_path' => 'library/incoming/live0001/source.epub',
        ]);
        $disk->put($live->incoming_path, 'live-bytes');

        // An orphan incoming dir (no owning submission).
        $disk->put('library/incoming/orphan01/source.epub', 'orphan-bytes');

        // Immutable original + a stale interrupted-promotion temp file.
        $disk->put('library/original/sha256/ab/cd/abcd.epub', 'real-original');
        $disk->put('library/original/sha256/ab/cd/.tmp-stale-abcd.epub', 'leftover');

        // Dry-run (default): reports, deletes NOTHING.
        $this->artisan('mnemosyne:ingestion:cleanup', ['--min-age-hours' => 0])->assertSuccessful();
        $disk->assertExists('library/incoming/orphan01/source.epub');
        $disk->assertExists('library/incoming/live0001/source.epub');
        $disk->assertExists('library/original/sha256/ab/cd/.tmp-stale-abcd.epub');
        $disk->assertExists('library/original/sha256/ab/cd/abcd.epub');

        // --force: deletes ONLY the orphan dir and the stale tmp; never the
        // live submission's dir, never the immutable original.
        $this->artisan('mnemosyne:ingestion:cleanup', ['--force' => true, '--min-age-hours' => 0])
            ->assertSuccessful();
        $disk->assertMissing('library/incoming/orphan01/source.epub');
        $disk->assertMissing('library/original/sha256/ab/cd/.tmp-stale-abcd.epub');
        $disk->assertExists('library/incoming/live0001/source.epub');
        $disk->assertExists('library/original/sha256/ab/cd/abcd.epub');
    }

    // ---- Fix 3: free-space guard + --retry-failed -------------------------

    public function test_insufficient_space_fails_retryably_and_retry_reattempts(): void
    {
        $this->seedStructuredLibrary();
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        // Force the near-full-disk guard to trip for every entry.
        config(['mnemosyne.ingestion.min_free_disk_bytes' => 9_000_000_000_000_000]);

        // Does not crash; stops early with a retryable failure, no orphan.
        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id])->assertSuccessful();

        $this->assertSame(1, $run->entries()->where('status', 'import_failed')->count());
        $this->assertSame(2, $run->entries()->where('status', 'discovered')->count());
        $this->assertSame([], Storage::disk('data')->allFiles('library/incoming'));

        $failed = $run->entries()->where('status', 'import_failed')->sole();
        $this->assertStringContainsString('insufficient free disk space', (string) $failed->error);
        $this->assertStringNotContainsString('SECURITY:', (string) $failed->error);

        // Space restored + --retry-failed: the transient failure is retried
        // and all entries import.
        config(['mnemosyne.ingestion.min_free_disk_bytes' => 1_000]);
        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id, '--retry-failed' => true])
            ->assertSuccessful();

        $this->assertSame(3, BookSubmission::query()->count());
        $this->assertSame(3, $run->entries()->where('status', 'imported')->count());
    }

    public function test_retry_failed_reattempts_transient_but_never_containment(): void
    {
        $this->seedStructuredLibrary();
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        $entries = $run->entries()->orderBy('id')->get();
        $transient = $entries[0];
        $secure = $entries[1];

        // A transient failure (retryable) and a security/containment failure
        // (never auto-retried), as the import command records them.
        $transient->forceFill(['status' => 'import_failed', 'error' => 'copy failed: transient glitch'])->save();
        $secure->forceFill(['status' => 'import_failed', 'error' => 'SECURITY: source file missing, symlinked or outside the allowlisted root'])->save();

        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id, '--retry-failed' => true])
            ->assertSuccessful();

        // Transient re-queued and imported; the third (still discovered) too.
        $this->assertSame('imported', $transient->refresh()->status);

        // Containment failure left strictly untouched.
        $this->assertSame('import_failed', $secure->refresh()->status);
        $this->assertNull($secure->book_submission_id);
        $this->assertStringStartsWith('SECURITY:', (string) $secure->error);
    }
}
