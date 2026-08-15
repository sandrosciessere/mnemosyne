<?php

namespace App\Services\Retrieval;

use App\Exceptions\Library\InvalidTransitionException;
use App\Models\IngestionEvent;
use App\Models\RetrievalGeneration;
use App\Services\Ingestion\WorkerClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of retrieval generations (immutable-once-active component
 * profiles). Blue/green by construction: a new generation builds its own
 * chunks/vectors/ANN index side-by-side; activation is one transactional
 * status flip; superseded data survives until explicit cleanup.
 */
class RetrievalGenerationManager
{
    public function __construct(private readonly WorkerClient $worker) {}

    /**
     * Snapshot the current component configuration into a new (building)
     * generation. Embedding dimensions are taken from the worker's model
     * registry — never assumed.
     */
    public function create(): RetrievalGeneration
    {
        $retrieval = config('mnemosyne.retrieval');
        $modelKey = $retrieval['embedding']['model_key'];

        $models = collect($this->worker->getJson('/internal/v1/retrieval/models')['models'] ?? []);
        $model = $models->firstWhere('model_key', $modelKey);

        if ($model === null || empty($model['dims'])) {
            throw new InvalidTransitionException(
                'EMBEDDING_MODEL_UNKNOWN',
                "The worker does not know embedding model '{$modelKey}'.",
            );
        }

        $chunkerConfig = collect($retrieval['chunker'])->except('version')->sortKeys()->all();

        $config = [
            'chunker' => ['version' => $retrieval['chunker']['version'], 'config' => $chunkerConfig],
            'query_normalization_version' => $retrieval['query_normalization_version'],
            'lexical' => ['version' => $retrieval['lexical_version'], 'config' => $retrieval['lexical_config'] ?? 'simple'],
            'embedding' => [
                'provider' => 'worker-local',
                'model_key' => $modelKey,
                'hf_id' => $model['hf_id'] ?? null,
                'revision' => $model['revision'] ?? null,
                'dimensions' => (int) $model['dims'],
                'metric' => 'cosine',
                'normalized' => true,
                'batch_size' => $retrieval['embedding']['batch_size'],
            ],
            'fusion' => $retrieval['fusion'],
            'reranker' => [
                'provider' => 'worker-local',
                'model_key' => $retrieval['reranker']['model_key'],
            ],
            'ann' => $retrieval['ann'],
        ];

        $generation = new RetrievalGeneration;
        $generation->forceFill([
            'status' => 'building',
            'config' => $config,
            'chunker_config_hash' => hash('sha256', json_encode(
                [$retrieval['chunker']['version'], $chunkerConfig],
                JSON_UNESCAPED_UNICODE,
            )),
            'chunker_version' => $retrieval['chunker']['version'],
            'embedding_model_key' => $modelKey,
            'embedding_dimensions' => (int) $model['dims'],
        ])->save();

        $this->ensureAnnIndex($generation);

        return $generation;
    }

    /**
     * Per-generation partial HNSW index over the dimension-cast vector:
     * different models/dimensions can never share an ANN index and
     * generations stay physically isolated.
     */
    public function ensureAnnIndex(RetrievalGeneration $generation): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $ann = $generation->config['ann'];
        $dims = (int) $generation->embedding_dimensions;

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON retrieval_embeddings '.
            'USING hnsw ((embedding::vector(%d)) vector_cosine_ops) '.
            'WITH (m = %d, ef_construction = %d) '.
            'WHERE retrieval_generation_id = %d',
            $generation->annIndexName(),
            $dims,
            (int) $ann['hnsw_m'],
            (int) $ann['hnsw_ef_construction'],
            $generation->id,
        ));
    }

    /**
     * Activate a built generation. Policy: it must contain at least one
     * ready asset unless the caller explicitly accepts an empty
     * activation (bootstrap scenarios).
     */
    public function activate(RetrievalGeneration $generation, bool $allowEmpty = false): void
    {
        DB::transaction(function () use ($generation, $allowEmpty) {
            $generation = RetrievalGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->status === 'active') {
                return;
            }

            if ($generation->status !== 'building') {
                throw new InvalidTransitionException(
                    'GENERATION_NOT_ACTIVATABLE',
                    'Only building generations can be activated.',
                );
            }

            $ready = $generation->assetStates()->where('status', 'ready')->count();
            $failed = $generation->assetStates()->where('status', 'failed')->count();

            if ($ready === 0 && ! $allowEmpty) {
                throw new InvalidTransitionException(
                    'GENERATION_EMPTY',
                    'Refusing to activate a generation with zero ready assets (use --allow-empty to override).',
                );
            }

            RetrievalGeneration::query()
                ->where('status', 'active')
                ->update(['status' => 'superseded', 'updated_at' => now()]);

            try {
                $generation->forceFill([
                    'status' => 'active',
                    'activated_at' => now(),
                ])->save();
            } catch (UniqueConstraintViolationException $exception) {
                // Concurrent activation (M2 backlog F8): the one-active
                // partial unique index made the invariant hold; surface
                // the loser as a controlled domain outcome, never a 500.
                throw new InvalidTransitionException(
                    'GENERATION_ACTIVATION_CONFLICT',
                    'Another generation was activated concurrently; retry after it settles.',
                );
            }

            IngestionEvent::record('retrieval.generation_activated', payload: [
                'generation' => $generation->public_id,
                'ready_assets' => $ready,
                'failed_assets' => $failed,
            ]);
        });
    }
}
