<?php

namespace App\Services\Answers;

use App\Enums\QueryIntent;

/**
 * Versioned intent → retrieval behavior mapping (retrieval-policy
 * 1.0.0). Reranking stays OFF by default everywhere: Milestone 2
 * measured seconds of CPU latency for mixed quality. The M2 generation
 * configuration is never modified — policies only choose mode/budgets
 * on top of it.
 */
class RetrievalPolicyResolver
{
    public const VERSION = 'retrieval-policy 1.0.0';

    public function resolve(QueryIntent $intent, int $scopeSize): RetrievalPolicy
    {
        return match ($intent) {
            // Exact first; the packet builder falls back to hybrid when
            // the literal is absent (lexical+dense still find the scene).
            QueryIntent::QuoteLocation => new RetrievalPolicy(
                mode: 'hybrid', topK: 10, perBook: false, perBookTopK: 0,
                exactFirst: true, rerank: false, expansionTopK: 16,
            ),
            QueryIntent::PointLookup => new RetrievalPolicy(
                mode: 'hybrid', topK: 10, perBook: false, perBookTopK: 0,
                exactFirst: false, rerank: false, expansionTopK: 18,
            ),
            // Per-book evidence opportunity BEFORE global selection: a
            // single high-scoring book must not monopolize the packet.
            QueryIntent::ComparativeMultiBook => new RetrievalPolicy(
                mode: 'hybrid', topK: 18, perBook: true,
                perBookTopK: max(4, intdiv(18, max(1, $scopeSize))),
                exactFirst: false, rerank: false, expansionTopK: 24,
            ),
            // LOCAL_EXPLANATION and the detected-but-capability-limited
            // intents (global summary / longitudinal / tricky inference)
            // share the broad bounded pool; the latter additionally carry
            // an explicit capability notice on the run.
            default => new RetrievalPolicy(
                mode: 'hybrid', topK: 16, perBook: false, perBookTopK: 0,
                exactFirst: false, rerank: false, expansionTopK: 24,
            ),
        };
    }
}
