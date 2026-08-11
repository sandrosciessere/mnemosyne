<?php

namespace App\Jobs;

use App\Models\BookAsset;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Bus\Queueable as QueueableTrait;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Indexes one asset into one retrieval generation. Unique-until-
 * processing per (generation, asset) so hook + backfill + retry can all
 * fire without stacking duplicate jobs; the indexer itself is idempotent
 * and resume-safe, so duplicate execution is harmless anyway.
 */
class IndexAssetForRetrievalJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use QueueableTrait;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $generationId,
        public readonly int $assetId,
    ) {}

    public function uniqueId(): string
    {
        return 'retrieval-index:'.$this->generationId.':'.$this->assetId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(RetrievalIndexer $indexer): void
    {
        $generation = RetrievalGeneration::query()->find($this->generationId);
        $asset = BookAsset::query()->find($this->assetId);

        if ($generation === null || $asset === null) {
            return;
        }

        if (! in_array($generation->status, ['building', 'active'], true)) {
            return; // superseded/failed generations never gain new data
        }

        $indexer->indexAsset($generation, $asset);
    }
}
