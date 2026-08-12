<?php

namespace App\Console\Commands;

use App\Jobs\IndexAssetForRetrievalJob;
use App\Models\BookAsset;
use App\Models\RetrievalGeneration;
use App\Services\Retrieval\RetrievalIndexer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RetrievalIndexAssets extends Command
{
    protected $signature = 'mnemosyne:retrieval:index
        {--asset= : Public id of one asset}
        {--all-ready : Every retrieval-eligible asset missing/failed in the generation}
        {--generation= : Target generation public id (default: active, else newest building)}
        {--sync : Run inline instead of queueing (ops/testing)}';

    protected $description = 'Index retrieval-eligible assets into a generation (idempotent backfill; resumes failures)';

    public function handle(RetrievalIndexer $indexer): int
    {
        $generation = $this->resolveGeneration();

        if ($generation === null) {
            $this->error('No target generation (create one with mnemosyne:retrieval:create-generation).');

            return self::FAILURE;
        }

        $processed = 0;

        $batchSize = max(1, (int) config('mnemosyne.retrieval.backfill_batch_size'));

        // lazyById: keyset (id-cursor) pagination — bounded memory,
        // restart-safe and never skips rows the way offset pagination
        // does on a mutating table. Each asset is visited exactly once
        // per run; the indexer/job layer is idempotent on top.
        foreach ($this->resolveAssets($generation)->lazyById($batchSize) as $asset) {
            if ($processed === 0) {
                $this->info("indexing into generation {$generation->public_id}".($this->option('sync') ? ' (sync)' : ''));
            }
            $processed++;

            if ($this->option('sync')) {
                $state = $indexer->indexAsset($generation, $asset);
                $this->line(sprintf(
                    '  %s → %s (%d chunks, %d embedded)%s',
                    $asset->public_id,
                    $state->status,
                    $state->chunk_count,
                    $state->embedded_count,
                    $state->last_error_code !== null ? ' ['.$state->last_error_code.']' : '',
                ));
            } else {
                IndexAssetForRetrievalJob::dispatch($generation->id, $asset->id)
                    ->onConnection(config('mnemosyne.ingestion.queue_connection'))
                    ->onQueue(config('mnemosyne.retrieval.queue'));
                $this->line("  queued {$asset->public_id}");
            }
        }

        if ($processed === 0) {
            $this->info('Nothing to index: every eligible asset is already ready in this generation.');
        } else {
            $this->info("{$processed} asset(s) ".($this->option('sync') ? 'indexed' : 'queued').'.');
        }

        return self::SUCCESS;
    }

    private function resolveGeneration(): ?RetrievalGeneration
    {
        $requested = (string) $this->option('generation');

        if ($requested !== '') {
            return RetrievalGeneration::query()->where('public_id', $requested)->first();
        }

        return RetrievalGeneration::active()
            ?? RetrievalGeneration::query()->where('status', 'building')->latest('id')->first();
    }

    /** @return Builder<BookAsset> query for lazyById iteration */
    private function resolveAssets(RetrievalGeneration $generation)
    {
        if ($this->option('asset')) {
            return BookAsset::query()
                ->where('public_id', (string) $this->option('asset'));
        }

        if (! $this->option('all-ready')) {
            $this->warn('Specify --asset=<ulid> or --all-ready.');

            return BookAsset::query()->whereRaw('1 = 0');
        }

        // Eligible assets missing from the generation or not yet ready in
        // it (failed/interrupted states are resumed — the indexer is
        // idempotent, so re-touching them is safe).
        return BookAsset::query()
            ->whereIn('ingestion_status', ['ready_for_enrichment', 'ready_for_enrichment_with_warnings'])
            ->whereNotIn('id', function ($query) use ($generation) {
                $query->select('book_asset_id')
                    ->from('retrieval_asset_states')
                    ->where('retrieval_generation_id', $generation->id)
                    ->where('status', 'ready');
            });
    }
}
