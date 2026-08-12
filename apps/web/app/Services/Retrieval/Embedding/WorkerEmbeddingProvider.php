<?php

namespace App\Services\Retrieval\Embedding;

use App\Exceptions\Library\InvalidTransitionException;
use App\Exceptions\Library\WorkerUnavailableException;
use App\Services\Ingestion\WorkerClient;

/**
 * Local-first embedding via the authenticated Python worker: book text
 * never leaves this server. Every vector is validated for dimensionality
 * and finiteness before use.
 */
class WorkerEmbeddingProvider implements EmbeddingProvider
{
    private ?array $identity = null;

    public function __construct(
        private readonly WorkerClient $client,
        private readonly string $modelKey,
        private readonly int $expectedDims,
        private readonly int $batchSize = 32,
    ) {}

    public function embedQuery(string $text): array
    {
        return $this->embed([$text], 'query')[0];
    }

    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batchSize)) as $batch) {
            $vectors = array_merge($vectors, $this->embed($batch, 'passage'));
        }

        return $vectors;
    }

    /** @return list<list<float>> */
    private function embed(array $texts, string $inputType): array
    {
        $response = $this->client->postJson('/internal/v1/retrieval/embed', [
            'model_key' => $this->modelKey,
            'input_type' => $inputType,
            'texts' => array_values($texts),
        ]);

        $this->identity = ($response['model_identity'] ?? []) + ['model_key' => $this->modelKey];
        $vectors = $response['vectors'] ?? [];

        if (count($vectors) !== count($texts)) {
            throw new WorkerUnavailableException('Embedding count mismatch from worker.');
        }

        foreach ($vectors as $vector) {
            if (count($vector) !== $this->expectedDims) {
                throw new InvalidTransitionException(
                    'EMBEDDING_DIMENSION_MISMATCH',
                    sprintf('Expected %d dims, worker returned %d.', $this->expectedDims, count($vector)),
                );
            }

            foreach ($vector as $component) {
                if (! is_finite($component)) {
                    throw new InvalidTransitionException(
                        'EMBEDDING_NOT_FINITE',
                        'Worker returned a non-finite embedding component.',
                    );
                }
            }
        }

        return $vectors;
    }

    public function modelIdentity(): array
    {
        return ($this->identity ?? []) + [
            'model_key' => $this->modelKey,
            'hf_id' => null,
            'revision' => null,
            'dims' => $this->expectedDims,
            'metric' => 'cosine',
            'normalized' => true,
        ];
    }

    public function dimensions(): int
    {
        return $this->expectedDims;
    }
}
