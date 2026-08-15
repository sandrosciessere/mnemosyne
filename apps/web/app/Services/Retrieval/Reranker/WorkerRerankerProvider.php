<?php

namespace App\Services\Retrieval\Reranker;

use App\Services\Ingestion\WorkerClient;

/** Local CPU cross-encoder via the authenticated worker. */
class WorkerRerankerProvider implements RerankerProvider
{
    private ?array $identity = null;

    public function __construct(
        private readonly WorkerClient $client,
        private readonly string $modelKey,
    ) {}

    public function rerank(string $query, array $candidates): array
    {
        // Dedicated deadline: a synchronous optional reranker must never
        // hold a user request for the general worker timeout (~330 s).
        $timeout = (int) config('mnemosyne.retrieval.reranker.timeout_seconds', 30);

        $response = $this->client->postJson('/internal/v1/retrieval/rerank', [
            'model_key' => $this->modelKey,
            'query' => $query,
            'candidates' => array_map(fn ($candidate) => [
                'id' => $candidate['id'],
                'text' => mb_substr($candidate['text'], 0, 8000),
            ], $candidates),
        ], $timeout);

        $this->identity = ($response['model_identity'] ?? []) + ['model_key' => $this->modelKey];

        $scores = [];
        foreach ($response['scores'] ?? [] as $entry) {
            // Only genuine finite numbers count (M2 backlog F2): strings
            // like "NaN"/"Infinity" cast to 0.0 in PHP and would fake a
            // usable score; nulls and non-numerics are dropped too.
            if (! is_array($entry) || ! isset($entry['id']) || ! isset($entry['score'])) {
                continue;
            }

            $score = $entry['score'];

            if ((is_int($score) || is_float($score)) && is_finite((float) $score)) {
                $scores[(string) $entry['id']] = (float) $score;
            }
        }

        return $scores;
    }

    public function modelIdentity(): array
    {
        return ($this->identity ?? []) + ['model_key' => $this->modelKey, 'hf_id' => null, 'revision' => null];
    }
}
