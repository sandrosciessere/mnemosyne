export type GenerationStatus = 'building' | 'active' | 'superseded' | 'failed';

export interface ChunkerConfig {
    target_chars: number;
    min_chars: number;
    max_chars: number;
    overlap_tail_chars: number;
}

export interface EmbeddingIdentity {
    model_key: string;
    hf_id: string;
    revision: string;
    dimensions: number;
    metric: string;
}

export interface RerankerIdentity {
    provider: string;
    model_key: string;
}

export interface FusionConfig {
    algorithm: string;
    version: string | number;
    k: number;
    weights: Record<string, number>;
}

export interface GenerationAssetCounts {
    ready: number;
    pending: number;
    chunking: number;
    embedding: number;
    failed: number;
}

export interface GenerationFailure {
    asset: string;
    filename: string;
    error_code: string | null;
    error_message: string | null;
    attempts: number;
}

/** Admin summary of one retrieval generation (index page). */
export interface GenerationSummary {
    public_id: string;
    status: GenerationStatus;
    chunker_version: string;
    chunker_config: ChunkerConfig;
    embedding: EmbeddingIdentity;
    reranker: RerankerIdentity | null;
    fusion: FusionConfig | null;
    assets: GenerationAssetCounts;
    chunks: number;
    embeddings: number;
    activated_at: string | null;
    recent_failures: GenerationFailure[];
}

/** Evidence span as returned by the retrieval API (exact source provenance). */
export interface EvidenceSpanApi {
    source_node_id: string;
    spine_index: number | null;
    href: string | null;
    fragment: string | null;
    node_type: string | null;
    heading_path: string[];
    canonical_start: number;
    canonical_end: number;
    utf16_start: number;
    utf16_end: number;
    chunk_start: number;
    chunk_end: number;
    source_hash: string;
}

export interface ExactMatch {
    text: string;
    chunk_start: number;
    chunk_end: number;
    canonical_start: number;
    canonical_end: number;
}

/** Per-component debug scores; null when the component did not rank the result. */
export interface SearchScores {
    exact_rank: number | null;
    lexical_rank: number | null;
    lexical_score: number | null;
    dense_rank: number | null;
    dense_similarity: number | null;
    rrf_score: number | null;
    rerank_score: number | null;
}

/** One ranked evidence result from POST /api/v1/retrieval/search. */
export interface SearchResult {
    rank: number;
    chunk_id: string;
    book_asset_id: string;
    book: { title: string; work_title: string | null };
    heading_path: string[];
    spine_index: number | null;
    excerpt: string;
    excerpt_truncated: boolean;
    char_count: number;
    evidence_spans: EvidenceSpanApi[];
    exact_matches: ExactMatch[];
    scores?: SearchScores;
}

/** Response meta from POST /api/v1/retrieval/search (debug fields admin-only). */
export interface SearchMeta {
    generation: string;
    mode: string;
    skipped_assets: string[];
    reranker_used: boolean;
    reranker_fallback_reason?: string | null;
    dense_unavailable?: boolean;
    /** 'phrase_too_long' when hybrid skipped the exact component. */
    exact_skipped_reason?: string | null;
    timings_ms?: Record<string, number> | null;
    /** Admin debug only: diagnostics incl. lexical_strategy (strict | or_fallback). */
    diagnostics?: Record<string, unknown> | null;
}

/** One neighbor chunk from GET /api/v1/retrieval/chunks/{chunk}/neighbors. */
export interface NeighborChunk {
    chunk_id: string;
    ordinal: number;
    heading_path: string[];
    excerpt: string;
    evidence_spans: EvidenceSpanApi[];
}
