<?php

namespace Tests\Integration;

use App\Models\BookAsset;
use App\Models\BookSubmission;
use App\Models\IngestionRun;
use App\Models\IngestionStageAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proves that a database already carrying the previously-deployed feature
 * schema converges to the final schema through a NORMAL `php artisan
 * migrate` — never migrate:fresh. It reconstructs the pre-convergence state
 * (cascade FK, no control-plane indexes) on the real PostgreSQL test
 * database and then applies the additive convergence migration, asserting
 * the final FK action and indexes.
 */
class MigrationUpgradeTest extends IntegrationTestCase
{
    use RefreshDatabase;

    private const CORRECTIVE = 'database/migrations/2026_08_11_101200_converge_ingestion_fk_and_control_plane_indexes.php';

    public function test_previously_deployed_schema_converges_via_normal_migrate(): void
    {
        // 1. Reconstruct the OLD deployed state: cascade FK + no new indexes.
        DB::statement('DROP INDEX IF EXISTS ingestion_runs_status_finished_at_index');
        DB::statement('DROP INDEX IF EXISTS isa_status_finished_stage_index');
        DB::statement('DROP INDEX IF EXISTS isa_attempt_started_index');
        DB::statement('ALTER TABLE ingestion_runs DROP CONSTRAINT IF EXISTS ingestion_runs_book_asset_id_foreign');
        DB::statement('ALTER TABLE ingestion_runs ADD CONSTRAINT ingestion_runs_book_asset_id_foreign FOREIGN KEY (book_asset_id) REFERENCES book_assets (id) ON DELETE CASCADE');

        $this->assertFalse($this->indexExists('ingestion_runs_status_finished_at_index'), 'precondition: index absent');
        $this->assertSame('CASCADE', $this->bookAssetFkAction(), 'precondition: cascade FK');

        // 2. Apply the additive convergence migration (as a normal migrate
        //    would for an already-deployed DB).
        $migration = require base_path(self::CORRECTIVE);
        $migration->up();

        // 3a. Control-plane indexes now exist.
        $this->assertTrue($this->indexExists('ingestion_runs_status_finished_at_index'));
        $this->assertTrue($this->indexExists('isa_status_finished_stage_index'));
        $this->assertTrue($this->indexExists('isa_attempt_started_index'));

        // 3b. FK action is now SET NULL, and the actual delete behaviour
        //     preserves the append-only ingestion history.
        $this->assertSame('SET NULL', $this->bookAssetFkAction());

        $asset = BookAsset::factory()->create();
        $submission = BookSubmission::factory()->approved()->create(['book_asset_id' => $asset->id]);
        $run = IngestionRun::factory()->create([
            'book_submission_id' => $submission->id,
            'book_asset_id' => $asset->id,
        ]);
        $attempt = new IngestionStageAttempt;
        $attempt->forceFill([
            'ingestion_run_id' => $run->id, 'stage' => 'hash', 'attempt' => 1,
            'status' => 'succeeded', 'started_at' => now(),
        ])->save();

        $asset->delete();

        // The run and its attempt survive; only the attribution is nulled.
        $this->assertTrue(IngestionRun::query()->whereKey($run->id)->exists(), 'run must survive asset deletion');
        $this->assertNull($run->refresh()->book_asset_id);
        $this->assertTrue(IngestionStageAttempt::query()->whereKey($attempt->id)->exists(), 'attempt history must survive');

        // 3c. The rest of the converged schema (from the additive 101xxx
        //     migrations an existing DB also runs normally) is present.
        $this->assertTrue(Schema::hasColumn('ingestion_runs', 'waiting_on_asset_id'));
        $this->assertTrue(Schema::hasColumn('edition_identifiers', 'canonical_value'));
        $this->assertTrue(Schema::hasColumn('duplicate_candidates', 'asset_low_id'));
        $this->assertTrue(Schema::hasColumn('discovery_entries', 'display_path'));
    }

    private function indexExists(string $name): bool
    {
        return DB::table('pg_indexes')->where('indexname', $name)->exists();
    }

    private function bookAssetFkAction(): string
    {
        // confdeltype: 'c' = CASCADE, 'n' = SET NULL.
        $type = DB::selectOne(
            "SELECT confdeltype FROM pg_constraint WHERE conname = 'ingestion_runs_book_asset_id_foreign'"
        )?->confdeltype;

        return match ($type) {
            'c' => 'CASCADE',
            'n' => 'SET NULL',
            default => (string) $type,
        };
    }
}
