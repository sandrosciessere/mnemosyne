<?php

namespace App\Services\Retrieval\Reranker;

interface RerankerProvider
{
    /**
     * @param  list<array{id: string, text: string}>  $candidates
     * @return array<string, float> candidate id → relevance score
     *                              (higher is better, uncalibrated)
     */
    public function rerank(string $query, array $candidates): array;

    /** @return array{model_key: string, hf_id: ?string, revision: ?string} */
    public function modelIdentity(): array;
}
