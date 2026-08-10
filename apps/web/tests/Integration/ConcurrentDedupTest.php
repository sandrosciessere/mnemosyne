<?php

namespace Tests\Integration;

use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Models\User;
use App\Services\Ingestion\HashStage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genuine multi-process concurrency against the real PostgreSQL database:
 * two processes hash byte-identical uploads at the same instant. The
 * `book_assets.sha256` unique constraint guarantees a single physical
 * asset no matter who wins the race, and HashStage converges the loser
 * onto it instead of crashing — so the ASSERTION (exactly one asset, no
 * fatal child) is deterministic even though the interleaving is not. This
 * is not a RefreshDatabase test: the children must see committed rows, so
 * setup commits for real and tearDown cleans up explicitly.
 */
class ConcurrentDedupTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $submissionIds = [];

    private ?int $userId = null;

    private ?string $sha = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available.');
        }

        // This test deliberately does NOT use RefreshDatabase (forked
        // children must see committed rows), so ensure the schema exists
        // even when run in isolation against a fresh pg-test container.
        if (! Schema::connection('pgsql')->hasTable('book_assets')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        // Explicit cleanup (no RefreshDatabase wrapper here). Deleting the
        // submissions cascades their runs and events; then the shared asset.
        if ($this->submissionIds !== []) {
            BookSubmission::query()->whereIn('id', $this->submissionIds)->delete();
        }
        if ($this->sha !== null) {
            BookAsset::query()->where('sha256', $this->sha)->delete();
        }
        if ($this->userId !== null) {
            User::query()->where('id', $this->userId)->delete();
        }

        parent::tearDown();
    }

    public function test_simultaneous_first_upload_of_identical_bytes_converges_to_one_asset(): void
    {
        $disk = $this->storageDisk();
        $bytes = 'PK-concurrent-identical-'.Str::random(16);
        $this->sha = hash('sha256', $bytes);

        $user = User::factory()->create();
        $this->userId = $user->id;

        // Two independent submissions, each with its OWN incoming copy of the
        // identical bytes and its own queued run — exactly the bulk-import
        // "same book submitted twice at once" situation.
        $runs = [];
        for ($i = 0; $i < 2; $i++) {
            $submission = new BookSubmission;
            $submission->forceFill([
                'user_id' => $user->id,
                'source_type' => 'upload',
                'original_filename' => 'race.epub',
                'status' => 'approved',
                'priority' => 'normal',
            ])->save();
            $this->submissionIds[] = $submission->id;

            $incoming = 'library/incoming/'.$submission->public_id.'/source.epub';
            $disk->put($incoming, $bytes);
            $submission->forceFill(['incoming_path' => $incoming])->save();

            $run = new IngestionRun;
            $run->forceFill([
                'book_submission_id' => $submission->id,
                'pipeline_version' => '1',
                'status' => 'queued',
                'priority' => 'normal',
                'progress' => 0,
                'queued_at' => now(),
                'heartbeat_at' => now(),
                'correlation_id' => (string) Str::uuid(),
            ])->save();
            $runs[] = $run->id;
        }

        $barrier = $this->testDataRoot.'/race-start-'.Str::random(8);

        // Fork two children that both block on the barrier, then hash at once.
        $pids = [];
        foreach ($runs as $runId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // CHILD: drop the inherited PDO handles and reconnect, then
                // wait for the barrier and run the hash stage.
                $code = 0;
                try {
                    DB::purge('pgsql');
                    DB::purge('pgsql_locks');
                    $waited = 0;
                    while (! file_exists($barrier) && $waited < 5_000_000) {
                        usleep(1_000);
                        $waited += 1_000;
                    }
                    $run = IngestionRun::query()->findOrFail($runId);
                    app(HashStage::class)->run($run);
                } catch (\Throwable $exception) {
                    $code = 1;
                    fwrite(STDERR, 'child failed: '.$exception->getMessage()."\n");
                }
                exit($code);
            }
            $pids[] = $pid;
        }

        // Release both children as simultaneously as possible.
        File::put($barrier, '1');

        $childFailures = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (! (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)) {
                $childFailures++;
            }
        }

        DB::purge('pgsql');
        @unlink($barrier);

        // No child crashed, and exactly ONE physical asset exists for the
        // shared content — the loser converged instead of failing.
        $this->assertSame(0, $childFailures, 'a concurrent hash-stage child crashed');
        $this->assertSame(1, BookAsset::query()->where('sha256', $this->sha)->count());

        // Both submissions reference that single asset (one as the owner, one
        // as an exact duplicate) — provenance is preserved for both.
        $assetId = BookAsset::query()->where('sha256', $this->sha)->value('id');
        $linked = BookSubmission::query()->whereIn('id', $this->submissionIds)->pluck('book_asset_id')->all();
        $this->assertEquals([$assetId, $assetId], $linked);
    }

    private function storageDisk()
    {
        return Storage::disk('data');
    }
}
