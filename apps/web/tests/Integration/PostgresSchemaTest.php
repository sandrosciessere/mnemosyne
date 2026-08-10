<?php

namespace Tests\Integration;

use App\Enums\IngestionRunStatus;
use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PostgresSchemaTest extends IntegrationTestCase
{
    use RefreshDatabase;

    public function test_migrations_created_all_library_tables_on_postgres(): void
    {
        foreach ([
            'works', 'contributors', 'editions', 'edition_contributors',
            'edition_identifiers', 'book_assets', 'book_submissions',
            'ingestion_runs', 'ingestion_stage_attempts', 'ingestion_events',
            'duplicate_candidates', 'system_settings', 'book_access_grants',
        ] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "missing table {$table}",
            );
        }
    }

    public function test_jsonb_round_trip_and_query(): void
    {
        $asset = BookAsset::factory()->create();
        $asset->forceFill([
            'extracted_metadata' => [
                'title' => 'JSONB Röundtrip — ✓',
                'identifiers' => [['scheme' => 'isbn13', 'value' => '9780316769488']],
            ],
        ])->save();

        $found = BookAsset::query()
            ->where('extracted_metadata->title', 'JSONB Röundtrip — ✓')
            ->sole();

        $this->assertSame($asset->id, $found->id);
        $this->assertSame('isbn13', $found->extracted_metadata['identifiers'][0]['scheme']);
    }

    public function test_partial_unique_index_blocks_second_active_run_on_postgres(): void
    {
        $submission = BookSubmission::factory()->approved()->create();
        IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Running,
        ]);

        $this->expectException(QueryException::class);
        IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'status' => IngestionRunStatus::Queued,
        ]);
    }

    public function test_sha256_uniqueness_enforced_on_postgres(): void
    {
        $asset = BookAsset::factory()->create();

        $this->expectException(QueryException::class);
        BookAsset::factory()->create(['sha256' => $asset->sha256]);
    }

    public function test_database_cache_lock_is_mutually_exclusive(): void
    {
        // The lock store must live on its own connection: a failed lock
        // insert inside the RefreshDatabase wrapper transaction would
        // poison it (PostgreSQL aborts the whole transaction).
        config([
            'database.connections.pgsql_locks' => config('database.connections.pgsql'),
            'cache.stores.database.connection' => 'pgsql_locks',
            'cache.stores.database.lock_connection' => 'pgsql_locks',
        ]);

        $store = Cache::store('database');

        $first = $store->lock('integration-lock-test', 30);
        $second = $store->lock('integration-lock-test', 30);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get(), 'second lock acquisition must fail while the first is held');

        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }
}
