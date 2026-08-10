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
            ->where('display_path', 'Ada Example/First Book/first.epub')
            ->sole();
        $this->assertSame('discovered', $entry->status);
        // Authoritative relative_path is the base64 of the raw bytes.
        $this->assertSame(base64_encode('Ada Example/First Book/first.epub'), $entry->relative_path);
        $this->assertSame('Ada Example/First Book/first.epub', $entry->rawRelativePath());
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

        $entry = $run->entries()->where('display_path', 'Ada Example/First Book/first.epub')->sole();
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

    public function test_resume_aborts_when_the_source_root_changed(): void
    {
        // Interrupt after one epub so the run is resumable.
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--limit' => 1])
            ->assertSuccessful();
        $run = DiscoveryRun::query()->sole();
        $this->assertSame('aborted', $run->status);

        // Repoint the same source name at a DIFFERENT root: a cursor from
        // root A must never be replayed against root B.
        $otherRoot = sys_get_temp_dir().'/mnemosyne-other-root-'.getmypid();
        File::deleteDirectory($otherRoot);
        File::makeDirectory($otherRoot.'/Someone/Book', 0755, true);
        File::put($otherRoot.'/Someone/Book/other.epub', 'x');
        config(['mnemosyne.import_sources' => ['test-lib' => $otherRoot]]);

        try {
            $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id])
                ->assertFailed();

            // The run is untouched: still aborted, cursor intact, no new rows.
            $run->refresh();
            $this->assertSame('aborted', $run->status);
            $this->assertSame(1, $run->entries()->count());
        } finally {
            File::deleteDirectory($otherRoot);
        }
    }

    public function test_dry_run_resume_does_not_mutate_or_strand_the_run(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--limit' => 1])
            ->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        $before = $run->only(['status', 'finished_at', 'last_path', 'files_seen', 'epubs_found', 'entries_created']);
        $entriesBefore = $run->entries()->count();

        // Dry-run + resume previews remaining work but persists NOTHING about
        // lifecycle — the run must not flip to running or get stranded.
        $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id, '--dry-run' => true])
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame($before['status'], $run->status);
        $this->assertEquals($before['finished_at'], $run->finished_at);
        $this->assertSame($before['last_path'], $run->last_path);
        $this->assertSame($before['files_seen'], $run->files_seen);
        $this->assertSame($before['epubs_found'], $run->epubs_found);
        $this->assertSame($before['entries_created'], $run->entries_created);
        $this->assertSame($entriesBefore, $run->entries()->count());
    }

    public function test_resume_counters_are_not_double_counted(): void
    {
        // First pass stops after one epub.
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--limit' => 1])
            ->assertSuccessful();
        $run = DiscoveryRun::query()->sole();

        // Resume to completion.
        $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id])
            ->assertSuccessful();

        $run->refresh();
        // 4 files (3 epubs + notes.txt) re-walked once, counted once.
        $this->assertSame(4, $run->files_seen);
        $this->assertSame(3, $run->epubs_found);
        $this->assertSame(3, $run->entries_created);
        $this->assertSame(0, $run->unreadable);

        // Resuming a completed run re-walks but must NOT inflate counters.
        $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id])
            ->assertSuccessful();
        $run->refresh();
        $this->assertSame(4, $run->files_seen);
        $this->assertSame(3, $run->epubs_found);
        $this->assertSame(3, $run->entries_created);
    }

    public function test_traversal_is_byte_ordered_and_resume_is_exact_even_under_a_locale(): void
    {
        // A locale can collate scandir differently from byte order; the walk
        // must stay byte-wise (strcmp) so the resume cursor never skips or
        // duplicates. Best-effort: only meaningful if the locale is present.
        setlocale(LC_ALL, 'en_US.UTF-8', 'C.UTF-8', 'en_US.utf8');

        $root = sys_get_temp_dir().'/mnemosyne-order-'.getmypid();
        File::deleteDirectory($root);
        File::makeDirectory($root, 0755, true);
        // Mixed case + unicode; byte order (strcmp) is B < Z < a < á(0xC3).
        foreach (['Banana', 'Zebra', 'apple', "\xc3\xa1baco"] as $stem) {
            File::put($root.'/'.$stem.'.epub', $stem);
        }
        config(['mnemosyne.import_sources' => ['ordered' => $root]]);

        try {
            // Interrupt after 2 epubs, then resume: exactly 4 distinct
            // entries, none skipped or duplicated.
            $this->artisan('mnemosyne:library:discover', ['--source' => 'ordered', '--limit' => 2])
                ->assertSuccessful();
            $run = DiscoveryRun::query()->where('source_name', 'ordered')->sole();
            $this->assertSame(2, $run->entries()->count());

            $this->artisan('mnemosyne:library:discover', ['--resume' => $run->public_id])
                ->assertSuccessful();

            $paths = $run->entries()->orderBy('id')->pluck('display_path')->all();
            $this->assertSame(4, count($paths));
            $this->assertSame(count($paths), count(array_unique($paths)));
            // Persisted in byte order (== walk order == cursor order).
            $expected = ['Banana.epub', 'Zebra.epub', 'apple.epub', "\xc3\xa1baco.epub"];
            $this->assertSame($expected, $paths);
        } finally {
            File::deleteDirectory($root);
            setlocale(LC_ALL, 'C');
        }
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
            $this->assertDatabaseMissing('discovery_entries', ['display_path' => 'Ada Example/sneaky.epub']);

            // Even a manifest entry forged/staled into pointing at a
            // symlink is re-checked and refused at import time.
            $forged = new DiscoveryEntry;
            $forged->forceFill([
                'discovery_run_id' => $run->id,
                'relative_path' => DiscoveryEntry::encodeRelativePath('Ada Example/sneaky.epub'),
                'display_path' => 'Ada Example/sneaky.epub',
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
