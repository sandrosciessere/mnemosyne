<?php

namespace App\Services\Answers;

/**
 * Resolved per-intent retrieval behavior for one answer run. Immutable
 * value object; the resolver version is persisted on the run.
 */
class RetrievalPolicy
{
    public function __construct(
        public readonly string $mode,
        public readonly int $topK,
        public readonly bool $perBook,
        public readonly int $perBookTopK,
        public readonly bool $exactFirst,
        public readonly bool $rerank,
        public readonly int $expansionTopK,
    ) {}

    /** Diagnostic representation persisted with the run. */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'top_k' => $this->topK,
            'per_book' => $this->perBook,
            'per_book_top_k' => $this->perBookTopK,
            'exact_first' => $this->exactFirst,
            'rerank' => $this->rerank,
            'expansion_top_k' => $this->expansionTopK,
        ];
    }
}
