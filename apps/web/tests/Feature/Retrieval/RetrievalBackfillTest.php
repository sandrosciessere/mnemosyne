<?php

namespace Tests\Feature\Retrieval;

use App\Jobs\IndexAssetForRetrievalJob;
use App\Models\BookAsset;
use App\Models\RetrievalAssetState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsRetrievalArtifacts;
use Tests\TestCase;

/**
 * E2 regression: --all-ready backfill must iterate eligible assets in
 * bounded keyset-paginated batches (never materialize the whole library
 * as Eloquent models), visit every eligible asset exactly once and skip
 * already-ready ones.
 */
class RetrievalBackfillTest extends TestCase
{
    use BuildsRetrievalArtifacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        config(['mnemosyne.data_path' => Storage::disk('data')->path('')]);
        config(['mnemosyne.retrieval.backfill_batch_size' => 10]);
        Queue::fake();
    }

    public function test_backfill_batches_visit_each_eligible_asset_exactly_once(): void
    {
        $generation = $this->makeTestGeneration('active');

        // 27 eligible assets (> 2 batches of 10), 3 of them already ready
        // in this generation, plus one ineligible ingestion status.
        $assets = BookAsset::factory()->count(27)->readyForEnrichment()->create();
        BookAsset::factory()->create(['ingestion_status' => 'failed']);

        foreach ($assets->take(3) as $ready) {
            (new RetrievalAssetState)->forceFill([
                'retrieval_generation_id' => $generation->id,
                'book_asset_id' => $ready->id,
                'status' => 'ready',
                'source_content_sha256' => str_repeat('0', 64),
                'source_pipeline_version' => '1',
            ])->save();
        }

        $batchSelects = 0;
        DB::listen(function ($query) use (&$batchSelects) {
            if (str_contains($query->sql, 'from "book_assets"')
                && str_contains($query->sql, 'order by "id" asc')) {
                $batchSelects++;
            }
        });

        $this->artisan('mnemosyne:retrieval:index', ['--all-ready' => true])
            ->assertSuccessful();

        // Exactly the 24 not-yet-ready eligible assets, no duplicates.
        $expected = $assets->skip(3)->pluck('id')->sort()->values()->all();
        $dispatched = [];
        Queue::assertPushed(IndexAssetForRetrievalJob::class, 24);
        Queue::pushed(IndexAssetForRetrievalJob::class, function ($job) use (&$dispatched, $generation) {
            $dispatched[] = $job->assetId;

            return $job->generationId === $generation->id;
        });
        sort($dispatched);
        $this->assertSame($expected, $dispatched);
        $this->assertSame($dispatched, array_values(array_unique($dispatched)));

        // Keyset pagination actually ran in multiple bounded batches:
        // 24 rows / 10 per page = 3 selects.
        $this->assertGreaterThanOrEqual(3, $batchSelects, 'iteration must be paginated, not a single get()');
    }

    public function test_backfill_with_nothing_to_do_is_a_clean_noop(): void
    {
        $this->makeTestGeneration('active');

        $this->artisan('mnemosyne:retrieval:index', ['--all-ready' => true])
            ->expectsOutputToContain('Nothing to index')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
