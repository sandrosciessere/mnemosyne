<?php

namespace Tests\Integration;

use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticEpub;
use Tests\TestCase;

/**
 * Base class for PostgreSQL/worker integration tests. Requires the
 * compose "test" profile (pg-test on 127.0.0.1:8109, ai-worker-test on
 * 127.0.0.1:8108) — `make test-integration` starts it and exports the
 * required environment. Without that environment every test self-skips,
 * so the plain host suite stays green.
 *
 * SAFETY GUARD: refuses to run unless the connected database name ends
 * with `_test`. The production database can never be migrated or wiped
 * by this suite.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected string $testDataRoot;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql') {
            $this->markTestSkipped('Integration suite requires DB_CONNECTION=pgsql (use make test-integration).');
        }

        parent::setUp();

        $database = (string) config('database.connections.pgsql.database');

        if (! str_ends_with($database, '_test')) {
            $this->fail(
                "REFUSING to run integration tests against '{$database}': the database name must end with _test.",
            );
        }

        // Fail fast (not skip) when explicitly requested: make
        // test-integration must never silently skip everything.
        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $exception) {
            if (getenv('RUN_INTEGRATION') === '1') {
                $this->fail('pg-test is not reachable: '.$exception->getMessage());
            }

            $this->markTestSkipped('pg-test is not reachable.');
        }

        $this->testDataRoot = getenv('MNEMOSYNE_TEST_DATA_ROOT') ?: '/srv/data/mnemosyne/tmp/e2e-data';
        File::ensureDirectoryExists($this->testDataRoot.'/library/incoming');
        File::ensureDirectoryExists($this->testDataRoot.'/library/original');
        File::ensureDirectoryExists($this->testDataRoot.'/library/extracted');

        config([
            // Locks on a dedicated connection so they commit outside the
            // RefreshDatabase wrapper transaction (PG would abort it on a
            // contended lock insert).
            'database.connections.pgsql_locks' => config('database.connections.pgsql'),
            'cache.stores.database.connection' => 'pgsql_locks',
            'cache.stores.database.lock_connection' => 'pgsql_locks',
            'mnemosyne.data_path' => $this->testDataRoot,
            'filesystems.disks.data.root' => $this->testDataRoot,
            'mnemosyne.worker.base_url' => 'http://127.0.0.1:'.(getenv('MNEMOSYNE_TEST_WORKER_PORT') ?: '8108'),
            'mnemosyne.worker.internal_token' => 'mnemosyne-test-token',
            'mnemosyne.ingestion.queue_connection' => 'database',
            'mnemosyne.ingestion.lock_store' => 'database',
            'mnemosyne.ingestion.retry.backoff_seconds' => [0, 0, 0],
        ]);
    }

    protected function tearDown(): void
    {
        // The suite-owned data root is disposable by contract — it must
        // never point inside the real library tree.
        if (isset($this->testDataRoot)
            && str_contains($this->testDataRoot, '/tmp/')
            && ! str_contains($this->testDataRoot, 'library/original')) {
            File::deleteDirectory($this->testDataRoot.'/library/incoming');
            File::deleteDirectory($this->testDataRoot.'/library/original');
            File::deleteDirectory($this->testDataRoot.'/library/extracted');
        }

        parent::tearDown();
    }

    /** Skip (or fail under RUN_INTEGRATION=1) when the real worker is down. */
    protected function requireRealWorker(): void
    {
        Storage::forgetDisk('data');

        try {
            $response = Http::timeout(3)
                ->get(config('mnemosyne.worker.base_url').'/health/live');
            if (! $response->ok()) {
                throw new \RuntimeException('worker live check failed');
            }
        } catch (\Throwable $exception) {
            if (getenv('RUN_INTEGRATION') === '1') {
                $this->fail('ai-worker-test is not reachable: '.$exception->getMessage());
            }

            $this->markTestSkipped('ai-worker-test is not reachable.');
        }
    }

    /**
     * Build a synthetic EPUB fixture, submit it through the real HTTP
     * routes, approve it and drain the queue against the REAL worker.
     */
    protected function submitFixture(string $builderMethod, ?User $user = null): BookSubmission
    {
        $user = $user ?? User::factory()->create();
        $admin = User::factory()->admin()->create();

        $epubPath = sys_get_temp_dir().'/mnemosyne-fixture-'.uniqid().'.epub';
        SyntheticEpub::{$builderMethod}($epubPath);

        try {
            $this->actingAs($user)->post('/library/submissions', [
                'epub' => new UploadedFile($epubPath, $builderMethod.'.epub', 'application/epub+zip', null, true),
            ])->assertRedirect();
        } finally {
            File::delete($epubPath);
        }

        $submission = BookSubmission::query()->where('user_id', $user->id)->latest('id')->first();
        $this->actingAs($admin)->post('/admin/submissions/'.$submission->public_id.'/approve')->assertRedirect();
        $this->drainIngestionQueue();

        return $submission->refresh();
    }

    /** Absolute artifact dir for an asset at the current pipeline version. */
    protected function artifactDir(BookAsset $asset): string
    {
        return $this->testDataRoot.'/library/extracted/'.$asset->public_id.'/v1';
    }

    /** @return list<array<string, mixed>> all spine JSONL nodes in order */
    protected function readAllNodes(BookAsset $asset): array
    {
        $nodes = [];
        foreach (glob($this->artifactDir($asset).'/spine/*.jsonl') as $file) {
            foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
                if ($line !== '') {
                    $nodes[] = json_decode($line, true);
                }
            }
        }

        usort($nodes, fn ($a, $b) => $a['ordinal'] <=> $b['ordinal']);

        return $nodes;
    }

    /** Process queued ingestion jobs until the database queue drains. */
    protected function drainIngestionQueue(int $maxJobs = 120): void
    {
        for ($iteration = 0; $iteration < $maxJobs; $iteration++) {
            $pending = DB::table('jobs')->count();

            if ($pending === 0) {
                return;
            }

            $this->artisan('queue:work', [
                'connection' => 'database',
                '--once' => true,
                '--queue' => 'ingestion-high,ingestion-normal,ingestion-low',
                '--sleep' => 0,
                '--tries' => 1,
            ]);
        }

        $this->fail("Ingestion queue did not drain within {$maxJobs} jobs.");
    }
}
