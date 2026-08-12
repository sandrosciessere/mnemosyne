<?php

namespace App\Console\Commands;

use App\Models\RetrievalChunk;
use App\Models\RetrievalEmbedding;
use App\Models\RetrievalGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetrievalStatus extends Command
{
    protected $signature = 'mnemosyne:retrieval:status';

    protected $description = 'Show retrieval generations, per-asset indexing states and derived data volumes';

    public function handle(): int
    {
        $generations = RetrievalGeneration::query()->latest('id')->limit(10)->get();

        if ($generations->isEmpty()) {
            $this->info('No retrieval generations exist yet.');

            return self::SUCCESS;
        }

        foreach ($generations as $generation) {
            $states = $generation->assetStates()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status');

            $this->info(sprintf(
                '%s  [%s]  chunker %s  %s %dd',
                $generation->public_id,
                strtoupper($generation->status),
                $generation->chunker_version,
                $generation->embedding_model_key,
                $generation->embedding_dimensions,
            ));
            $this->line(sprintf(
                '  assets: ready %d, embedding %d, chunking %d, pending %d, failed %d',
                $states['ready'] ?? 0,
                $states['embedding'] ?? 0,
                $states['chunking'] ?? 0,
                $states['pending'] ?? 0,
                $states['failed'] ?? 0,
            ));
            $this->line(sprintf(
                '  chunks: %d, embeddings: %d',
                RetrievalChunk::query()->where('retrieval_generation_id', $generation->id)->count(),
                RetrievalEmbedding::query()->where('retrieval_generation_id', $generation->id)->count(),
            ));

            foreach ($generation->assetStates()->where('status', 'failed')->limit(5)->get() as $failed) {
                $this->warn("  failed asset_state {$failed->id}: {$failed->last_error_code} {$failed->last_error_message}");
            }
        }

        return self::SUCCESS;
    }
}
