<?php

namespace App\Services\Retrieval\Embedding;

/**
 * Narrow Milestone 2 embedding boundary (the full provider-routing
 * platform is a later milestone). The retrieval domain never touches
 * provider-specific response formats.
 */
interface EmbeddingProvider
{
    /** @return list<float> */
    public function embedQuery(string $text): array;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>> same order as input
     */
    public function embedDocuments(array $texts): array;

    /** @return array{model_key: string, hf_id: ?string, revision: ?string, dims: int, metric: string, normalized: bool} */
    public function modelIdentity(): array;

    public function dimensions(): int;
}
