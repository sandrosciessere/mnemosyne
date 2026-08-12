<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local data root
    |--------------------------------------------------------------------------
    |
    | Root of the persistent Mnemosyne data tree (library, models, cache…).
    | Inside containers this is the /data bind mount of /srv/data/mnemosyne.
    | The tree lives outside the Git repository on purpose.
    |
    */

    'data_path' => env('MNEMOSYNE_DATA_PATH', storage_path('app')),

    /*
    |--------------------------------------------------------------------------
    | Readiness checks
    |--------------------------------------------------------------------------
    |
    | Which checks /health/ready performs. Comma-separated: db, redis, storage.
    | The test environment narrows this list (no Redis server available).
    |
    */

    'readiness_checks' => array_filter(explode(',', (string) env('MNEMOSYNE_READINESS_CHECKS', 'db,redis,storage'))),

    /*
    |--------------------------------------------------------------------------
    | AI providers (abstraction only — no implementation yet)
    |--------------------------------------------------------------------------
    |
    | Every AI capability is resolved through a named provider per category.
    | The domain must never depend on a concrete provider (e.g. Ollama)
    | directly. Values are provider identifiers to be introduced by future
    | sessions; null means "not configured yet".
    |
    */

    'providers' => [
        'embeddings' => env('MNEMOSYNE_PROVIDER_EMBEDDINGS'),
        'reranker' => env('MNEMOSYNE_PROVIDER_RERANKER'),
        'generation' => env('MNEMOSYNE_PROVIDER_GENERATION'),
        'verifier' => env('MNEMOSYNE_PROVIDER_VERIFIER'),
        'deep_analysis' => env('MNEMOSYNE_PROVIDER_DEEP_ANALYSIS'),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal AI worker (service-to-service)
    |--------------------------------------------------------------------------
    |
    | The Python worker is reachable only on the backend Docker network.
    | Every internal call carries the shared token; the token lives in the
    | untracked .env and must never be committed or logged.
    |
    */

    'worker' => [
        'base_url' => env('MNEMOSYNE_WORKER_BASE_URL', 'http://ai-worker:8000'),
        'internal_token' => env('MNEMOSYNE_INTERNAL_TOKEN'),
        'timeout_seconds' => (int) env('MNEMOSYNE_WORKER_TIMEOUT', 330),
        'connect_timeout_seconds' => (int) env('MNEMOSYNE_WORKER_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | EPUB ingestion pipeline
    |--------------------------------------------------------------------------
    |
    | Versions identify what produced each artifact set; bump them when the
    | corresponding behavior changes so reprocessing can be targeted.
    | Limits are conservative but compatible with real books. Concurrency
    | stays low on purpose: this is a shared host.
    |
    */

    'ingestion' => [
        'pipeline_version' => '1',

        'queue_connection' => env('MNEMOSYNE_INGESTION_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'redis')),
        'concurrency' => (int) env('MNEMOSYNE_INGESTION_CONCURRENCY', 2),

        'max_upload_bytes' => (int) env('MNEMOSYNE_MAX_UPLOAD_BYTES', 150_000_000),
        'min_free_disk_bytes' => (int) env('MNEMOSYNE_MIN_FREE_DISK_BYTES', 50_000_000_000),

        'retry' => [
            'max_attempts_per_stage' => (int) env('MNEMOSYNE_INGESTION_MAX_ATTEMPTS', 3),
            'backoff_seconds' => [30, 120, 600],
        ],

        'stale_after_minutes' => (int) env('MNEMOSYNE_INGESTION_STALE_MINUTES', 30),

        'rate_limits' => [
            'submissions_per_hour' => (int) env('MNEMOSYNE_SUBMISSIONS_PER_HOUR', 30),
        ],

        // Store used for cross-process ingestion locks. Redis in the real
        // stack; host-side test runs override this to the database store.
        'lock_store' => env('MNEMOSYNE_LOCK_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filesystem import sources (allowlist)
    |--------------------------------------------------------------------------
    |
    | Roots mnemosyne:library:discover may traverse, as "name=path" pairs
    | separated by commas (e.g. "main=/import/library"). Empty by default:
    | the real library path is added only when its bind mount exists.
    | Paths outside this allowlist are never touched.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Retrieval foundation (Milestone 2)
    |--------------------------------------------------------------------------
    |
    | Component defaults captured into a RetrievalGeneration's immutable
    | config at creation time. Changing values here affects NEW
    | generations only — an active generation always uses its snapshot.
    | Chunk sizing was derived from the real corpus (avg node 427 chars,
    | p99 ≈ 2000) and the e5-small 512-token context (~1900 chars).
    |
    */

    'retrieval' => [
        'chunker' => [
            'version' => '1.0.0',
            'target_chars' => (int) env('MNEMOSYNE_CHUNK_TARGET_CHARS', 1200),
            'min_chars' => (int) env('MNEMOSYNE_CHUNK_MIN_CHARS', 250),
            'max_chars' => (int) env('MNEMOSYNE_CHUNK_MAX_CHARS', 2200),
            'overlap_tail_chars' => (int) env('MNEMOSYNE_CHUNK_OVERLAP_CHARS', 200),
        ],

        'query_normalization_version' => '1.0.0',
        // 1.1.0: strict websearch query + meaningful-token OR fallback
        // when strict yields zero rows (natural-language queries).
        // Generations snapshotting 1.0.0 keep strict-only behavior.
        'lexical_version' => '1.1.0',

        'embedding' => [
            'model_key' => env('MNEMOSYNE_EMBEDDING_MODEL_KEY', 'e5-small-v1'),
            'batch_size' => (int) env('MNEMOSYNE_EMBEDDING_BATCH', 32),
        ],

        'fusion' => [
            'algorithm' => 'rrf',
            'version' => '1.0.0',
            'k' => (int) env('MNEMOSYNE_RRF_K', 60),
            'weights' => [
                'exact' => 2.0,
                'lexical' => 1.0,
                'dense' => 1.0,
            ],
        ],

        'reranker' => [
            'model_key' => env('MNEMOSYNE_RERANKER_MODEL_KEY', 'mmarco-mini-v1'),
            'timeout_seconds' => (int) env('MNEMOSYNE_RERANK_TIMEOUT', 30),
        ],

        'search' => [
            // retrieve top N per component → fuse → rerank top M → final K
            'candidates_per_retriever' => (int) env('MNEMOSYNE_SEARCH_CANDIDATES', 40),
            'rerank_top_m' => (int) env('MNEMOSYNE_SEARCH_RERANK_M', 24),
            'max_top_k' => 25,
            'default_top_k' => 10,
            'max_query_chars' => 1000,
            // Exact literals are guaranteed findable across a chunk
            // partition boundary only while the pre-boundary portion fits
            // the chunker overlap (overlap_tail_chars). Capping accepted
            // phrases at the overlap keeps the guarantee unconditional —
            // raise both together or not at all.
            'max_exact_phrase_chars' => (int) env(
                'MNEMOSYNE_MAX_EXACT_PHRASE_CHARS',
                env('MNEMOSYNE_CHUNK_OVERLAP_CHARS', 200),
            ),
            // ANN under-return protection: overfetch factor before scope
            // filtering, plus pgvector iterative scans.
            'dense_overfetch' => (int) env('MNEMOSYNE_DENSE_OVERFETCH', 4),
            // Span-overlap ratio above which two candidates are duplicates.
            'dedupe_overlap_ratio' => 0.6,
        ],

        'queue' => env('MNEMOSYNE_RETRIEVAL_QUEUE', 'retrieval'),
        'concurrency' => (int) env('MNEMOSYNE_RETRIEVAL_CONCURRENCY', 2),
        // Keyset-pagination page size for --all-ready backfills: bounds
        // memory regardless of library size.
        'backfill_batch_size' => (int) env('MNEMOSYNE_RETRIEVAL_BACKFILL_BATCH', 500),

        'ann' => [
            'metric' => 'cosine',
            'hnsw_m' => (int) env('MNEMOSYNE_HNSW_M', 16),
            'hnsw_ef_construction' => (int) env('MNEMOSYNE_HNSW_EF_CONSTRUCTION', 64),
            'hnsw_ef_search' => (int) env('MNEMOSYNE_HNSW_EF_SEARCH', 60),
        ],
    ],

    'import_sources' => collect(explode(',', (string) env('MNEMOSYNE_IMPORT_SOURCES', '')))
        ->filter(fn ($pair) => str_contains($pair, '='))
        ->mapWithKeys(function ($pair) {
            [$name, $path] = explode('=', $pair, 2);

            return [trim($name) => trim($path)];
        })
        ->all(),

];
