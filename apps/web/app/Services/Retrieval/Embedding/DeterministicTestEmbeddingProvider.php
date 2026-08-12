<?php

namespace App\Services\Retrieval\Embedding;

use RuntimeException;

/**
 * TEST-ONLY deterministic embeddings: unit-normalized vectors derived
 * from token hashes, so texts sharing words are measurably closer.
 * Refuses to exist in production (fail closed).
 */
class DeterministicTestEmbeddingProvider implements EmbeddingProvider
{
    public const DIMS = 32;

    public function __construct()
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DeterministicTestEmbeddingProvider must never run in production.',
            );
        }
    }

    public function embedQuery(string $text): array
    {
        return $this->vector($text);
    }

    public function embedDocuments(array $texts): array
    {
        return array_map(fn ($text) => $this->vector($text), $texts);
    }

    /** Bag-of-hashed-tokens, L2-normalized. */
    private function vector(string $text): array
    {
        $vector = array_fill(0, self::DIMS, 0.0);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $bucket = hexdec(substr(hash('sha256', $token), 0, 6)) % self::DIMS;
            $vector[$bucket] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn ($component) => $component * $component, $vector))) ?: 1.0;

        return array_map(fn ($component) => round($component / $norm, 6), $vector);
    }

    public function modelIdentity(): array
    {
        return [
            'model_key' => 'deterministic-test',
            'hf_id' => null,
            'revision' => null,
            'dims' => self::DIMS,
            'metric' => 'cosine',
            'normalized' => true,
        ];
    }

    public function dimensions(): int
    {
        return self::DIMS;
    }
}
