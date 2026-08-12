<?php

namespace App\Services\Retrieval;

/**
 * Versioned per-mode query normalization (retrieval.query_normalization_
 * version). Each retriever gets exactly the treatment it needs — there
 * is deliberately NO single destructive pipeline:
 *  - exact: literal fidelity (trim + length cap only);
 *  - lexical: whitespace collapse (tokenization happens in PostgreSQL);
 *  - dense: whitespace collapse (model-specific prefixes are applied by
 *    the embedding provider, never here).
 */
class QueryNormalizer
{
    public function forExact(string $query): string
    {
        // Literal fidelity: NO truncation — silently shortening a literal
        // changes what the user asked for. Length policy is enforced
        // upstream (API 422 in exact mode; exact component skipped with a
        // diagnostic in hybrid mode).
        return trim($query);
    }

    public function forLexical(string $query): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($query)) ?? '';

        return mb_substr($collapsed, 0, (int) config('mnemosyne.retrieval.search.max_query_chars'));
    }

    public function forDense(string $query): string
    {
        return $this->forLexical($query);
    }
}
