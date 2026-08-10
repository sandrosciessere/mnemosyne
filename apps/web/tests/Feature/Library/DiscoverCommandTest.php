<?php

namespace Tests\Feature\Library;

use App\Models\BookSubmission;
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
        File::put($this->importRoot.'/Ada Example/First Book/first.epub', 'epub-one');
        File::put($this->importRoot.'/Ada Example/Second Book/SECOND.EPUB', 'epub-two');
        File::put($this->importRoot.'/Ada Example/Second Book/notes.txt', 'not an epub');

        config(['mnemosyne.import_sources' => ['test-lib' => $this->importRoot]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->importRoot);
        parent::tearDown();
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--dry-run' => true])
            ->expectsOutputToContain('2 epub files found, 0 submissions created')
            ->assertSuccessful();

        $this->assertDatabaseCount('book_submissions', 0);
    }

    public function test_discovery_creates_filesystem_submissions_with_hints(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])
            ->assertSuccessful();

        $this->assertSame(2, BookSubmission::query()->count());

        $submission = BookSubmission::query()
            ->where('original_filename', 'first.epub')
            ->sole();

        $this->assertSame('filesystem', $submission->source_type->value);
        $this->assertSame('pending_approval', $submission->status->value);
        $this->assertSame('low', $submission->priority->value);
        $this->assertSame('Ada Example', $submission->source_reference['author_hint']);
        $this->assertSame('First Book', $submission->source_reference['title_hint']);
        $this->assertNull($submission->user_id);
        Storage::disk('data')->assertExists($submission->incoming_path);
        $this->assertSame('epub-one', Storage::disk('data')->get($submission->incoming_path));
    }

    public function test_limit_and_unknown_source_are_handled(): void
    {
        $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib', '--limit' => 1])
            ->assertSuccessful();
        $this->assertSame(1, BookSubmission::query()->count());

        $this->artisan('mnemosyne:library:discover', ['--source' => 'nope'])
            ->assertFailed();
    }

    public function test_symlinked_files_outside_root_are_skipped(): void
    {
        $outside = sys_get_temp_dir().'/mnemosyne-outside-'.getmypid().'.epub';
        File::put($outside, 'outside-bytes');
        symlink($outside, $this->importRoot.'/Ada Example/sneaky.epub');

        try {
            $this->artisan('mnemosyne:library:discover', ['--source' => 'test-lib'])
                ->assertSuccessful();

            $this->assertSame(2, BookSubmission::query()->count());
            $this->assertDatabaseMissing('book_submissions', ['original_filename' => 'sneaky.epub']);
        } finally {
            File::delete($outside);
        }
    }
}
