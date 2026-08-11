<?php

namespace App\Services\Retrieval\Embedding;

use App\Models\RetrievalGeneration;
use App\Services\Ingestion\WorkerClient;
use RuntimeException;

class EmbeddingProviderFactory
{
    public function __construct(private readonly WorkerClient $client) {}

    /**
     * Provider bound to a generation's immutable embedding profile.
     * The 'deterministic-test' model key resolves to the test provider —
     * outside production only (the provider itself fails closed too).
     */
    public function forGeneration(RetrievalGeneration $generation): EmbeddingProvider
    {
        $profile = $generation->config['embedding'];

        if (($profile['model_key'] ?? '') === 'deterministic-test') {
            if (app()->environment('production')) {
                throw new RuntimeException('Test embedding provider configured in production.');
            }

            return new DeterministicTestEmbeddingProvider;
        }

        return new WorkerEmbeddingProvider(
            $this->client,
            $profile['model_key'],
            (int) $profile['dimensions'],
            (int) ($profile['batch_size'] ?? 32),
        );
    }
}
