<?php

namespace Tests\Feature\Library;

use App\Models\BookSubmission;
use App\Models\DiscoveryEntry;
use App\Models\DiscoveryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiscoverCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $importRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        Queue::fake();

        $this->importRoot = sys_get_temp_dir().'/mnemosyne-discover-test-'.getmypid();
        File::deleteDirectory($this->importRoot);
        File::makeDirectory($this->importRoot.'/Ada Example/First Book', 0755, true);
        File::makeDirectory($this->importRoot.'/Ada Example/Second Book', 0755, true);
        File::makeDirectory($this->importRoot.'/Zoe Author/Third Book', 0755, true);
        File::put($this->importRoot.'/Ada Example/First Book/first.epub', 'epub-one');
        File::put($this->importRoot.'/Ada Example/Second Book/SECOND.EPUB', 'epub-two');
        File::put($this->importRoot.'/Zoe Author/Third Book/third.epub', 'epub-three');
        File::put($this->importRoot.'/Ada Example/Second Book/notes.txt', 'not an epub');

        config(['mnemosyne.import_sources' => ['test-lib' => $this->importRoot]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->importRoot);
        parent::tearDown();
    }

    private function sourceFileCount(): int
    {
        return count(File::allFiles($this->importRoot));
    }

    public function test_discovery_is_read_only_and_persists_a_manifest(): void
    {
        $before = $this->sourceFileCount();

        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])
            ->assertSuccessful();

        // READ-ONLY: no submissions, nothing copied into incoming, source untouched.
        $this->assertDatabaseCount('book_submissions', 0);
        $this->assertSame([], Storage::disk('data')->allFiles('library/incoming'));
        $this->assertSame($before, $this->sourceFileCount());

        // Persistent manifest with counts and hints.
        $run = DiscoveryRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame('test-lib', $run->source_name);
        $this->assertSame(3, $run->epubs_found);
        $this->assertSame(3, $run->entries_created);
        $this->assertSame(4, $run->files_seen);
        $this->assertNotNull($run->finished_at);

        $entry = DiscoveryEntry::query()
            ->where('relative_path', 'Ada Example/First Book/first.epub')
            ->sole();
        $this->assertSame('discovered', $entry->status);
        $this->assertSame('Ada Example', $entry->author_hint);
        $this->assertSame('First Book', $entry->title_hint);
        $this->assertSame(8, $entry->size_bytes);
    }

    public function test_dry_run_persists_nothing(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('discovery_runs', 0);
        $this->assertDatabaseCount('discovery_entries', 0);
        $this->assertDatabaseCount('book_submissions', 0);
    }

    public function test_interrupted_discovery_resumes_without_duplicates(): void
    {
        // First pass stops after one epub (simulated interruption).
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--limit' => 1])
            ->assertSuccessful();

        $run = DiscoveryRun::query()->sole();
        $this->assertSame('aborted', $run->status);
        $this->assertSame(1, $run->entries()->count());
        $this->assertNotNull($run->last_path);

        // Resume completes the scan on the SAME run — no restart from zero.
        $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id])
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(3, $run->entries()->count());

        // Resuming a completed run is a safe no-op (idempotent).
        $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id])
            ->assertSuccessful();
        $this->assertSame(3, $run->refresh()->entries()->count());
        $this->assertDatabaseCount('book_submissions', 0);
    }

    public function test_import_consumes_manifest_and_is_restart_safe(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id, '--priority' => 'normal'])
            ->assertSuccessful();

        $this->assertSame(3, BookSubmission::query()->count());
        $this->assertSame(0, $run->entries()->where('status', 'discovered')->count());
        $this->assertSame(3, $run->entries()->where('status', 'imported')->count());

        $entry = $run->entries()->where('relative_path', 'Ada Example/First Book/first.epub')->sole();
        $submission = $entry->submission;
        $this->assertNotNull($submission);
        $this->assertSame('filesystem', $submission->source_type->value);
        $this->assertSame('normal', $submission->priority->value);
        $this->assertSame('pending_approval', $submission->status->value);
        $this->assertSame('Ada Example', $submission->source_reference['author_hint']);
        $this->assertSame($run->public_id, $submission->source_reference['discovery_run']);
        Storage::disk('data')->assertExists($submission->incoming_path);
        $this->assertSame('epub-one', Storage::disk('data')->get($submission->incoming_path));

        // Re-running import duplicates nothing (restart safety).
        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id])
            ->assertSuccessful();
        $this->assertSame(3, BookSubmission::query()->count());
    }

    public function test_import_respects_limit_and_can_continue(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id, '--limit' => 2])
            ->assertSuccessful();
        $this->assertSame(2, BookSubmission::query()->count());

        $this->artisan('mnemosyne:library:import', ['run' => $run->public_id])
            ->assertSuccessful();
        $this->assertSame(3, BookSubmission::query()->count());
    }

    public function test_symlinks_are_skipped_at_discovery_and_rechecked_at_import(): void
    {
        $outside = sys_get_temp_dir().'/mnemosyne-outside-'.getmypid().'.epub';
        File::put($outside, 'outside-bytes');
        symlink($outside, $this->importRoot.'/Ada Example/sneaky.epub');

        try {
            $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])->assertSuccessful();
            $run = DiscoveryRun::query()->sole();

            // Discovery refused the symlink.
            $this->assertSame(3, $run->entries()->count());
            $this->assertDatabaseMissing('discovery_entries', ['relative_path' => 'Ada Example/sneaky.epub']);

            // Even a manifest entry forged/staled into pointing at a
            // symlink is re-checked and refused at import time.
            $forged = new DiscoveryEntry;
            $forged->forceFill([
                'discovery_run_id' => $run->id,
                'relative_path' => 'Ada Example/sneaky.epub',
                'status' => 'discovered',
            ])->save();

            $this->artisan('mnemosyne:library:import', ['run' => $run->public_id])->assertSuccessful();

            $this->assertSame('import_failed', $forged->refresh()->status);
            $this->assertNull($forged->book_submission_id);
            $this->assertSame(3, BookSubmission::query()->count());
        } finally {
            File::delete($outside);
        }
    }
}
