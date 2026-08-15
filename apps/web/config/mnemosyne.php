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

    /*
    |--------------------------------------------------------------------------
    | Grounded answers (Milestone 3)
    |--------------------------------------------------------------------------
    |
    | question → intent → retrieval policy → EvidencePacket → structured
    | generation → independent verification → verified claims + citations.
    | Budgets are sized for the local CPU provider (prompt prefill is the
    | dominant cost: ~25 tok/s cold on the current host model); every
    | value here is part of answer reproducibility via the versions
    | persisted on each run.
    |
    */
    'answers' => [
        'query_classifier_version' => 'query-intent 1.0.0',
        'retrieval_profile_version' => 'retrieval-policy 1.0.0',
        'unitizer_version' => 'evidence-unitizer 1.0.0',
        'generator_prompt_version' => 'grounded-generator 1.0.0',
        'verifier_prompt_version' => 'grounded-verifier 1.0.0',

        'question_min_chars' => 3,
        'question_max_chars' => 2000,
        'scope_max_assets' => 100,

        'evidence' => [
            // Max source characters per EvidenceUnit (split at sentence
            // boundaries, never bytes; provenance preserved exactly).
            'unit_max_chars' => (int) env('MNEMOSYNE_ANSWER_UNIT_MAX_CHARS', 600),
            // Packet budget: bounded units and total source characters.
            'max_units' => (int) env('MNEMOSYNE_ANSWER_MAX_UNITS', 24),
            'max_chars' => (int) env('MNEMOSYNE_ANSWER_MAX_CHARS', 14000),
            // Sufficiency heuristic: below this many units the pipeline
            // performs its single bounded retrieval expansion.
            'min_sufficient_units' => (int) env('MNEMOSYNE_ANSWER_MIN_UNITS', 3),
            // Source-region diversity (two-stage packet selection): stage 1
            // admits at most this many units per retrieved chunk / per
            // source region (book+spine document) before stage 2 fills the
            // remaining budget with held-back units in relevance order.
            'max_initial_units_per_chunk' => (int) env('MNEMOSYNE_ANSWER_MAX_UNITS_PER_CHUNK', 3),
            'max_initial_units_per_region' => (int) env('MNEMOSYNE_ANSWER_MAX_UNITS_PER_REGION', 6),
        ],

        'retrieval' => [
            // Bounded deterministic query variants per subquestion.
            'max_query_variants' => (int) env('MNEMOSYNE_ANSWER_QUERY_VARIANTS', 5),
            // Local-episode neighborhood: ± chunks fetched around sibling
            // anchors for subquestions with no hits of their own.
            'neighborhood_window' => (int) env('MNEMOSYNE_ANSWER_NEIGHBORHOOD_WINDOW', 2),
            'neighborhood_anchors' => (int) env('MNEMOSYNE_ANSWER_NEIGHBORHOOD_ANCHORS', 2),
        ],

        // queued | retrieving | ... jobs run here (Horizon supervisor).
        'queue' => env('MNEMOSYNE_ANSWERS_QUEUE', 'answers'),
        'job_timeout_seconds' => (int) env('MNEMOSYNE_ANSWER_JOB_TIMEOUT', 1500),
        // Per-user concurrent active answer runs (queued or executing).
        'max_active_runs_per_user' => (int) env('MNEMOSYNE_ANSWER_MAX_ACTIVE_PER_USER', 2),
        // POST /api/v1/answers submissions per minute per user.
        'submissions_per_minute' => (int) env('MNEMOSYNE_ANSWER_SUBMISSIONS_PER_MINUTE', 6),

        'generator' => [
            'provider' => env('MNEMOSYNE_PROVIDER_GENERATION', 'ollama'),
            'model' => env('MNEMOSYNE_GENERATOR_MODEL', 'llama3.1:8b-instruct-q4_K_M'),
            'timeout_seconds' => (int) env('MNEMOSYNE_GENERATOR_TIMEOUT', 600),
            'max_retries' => (int) env('MNEMOSYNE_GENERATOR_MAX_RETRIES', 1),
            'num_ctx' => (int) env('MNEMOSYNE_GENERATOR_NUM_CTX', 16384),
            'max_output_tokens' => (int) env('MNEMOSYNE_GENERATOR_MAX_TOKENS', 1200),
            'max_claims' => 12,
        ],

        'verifier' => [
            'provider' => env('MNEMOSYNE_PROVIDER_VERIFIER', 'ollama'),
            'model' => env('MNEMOSYNE_VERIFIER_MODEL', 'llama3.1:8b-instruct-q4_K_M'),
            'timeout_seconds' => (int) env('MNEMOSYNE_VERIFIER_TIMEOUT', 300),
            'max_retries' => (int) env('MNEMOSYNE_VERIFIER_MAX_RETRIES', 1),
            'num_ctx' => (int) env('MNEMOSYNE_VERIFIER_NUM_CTX', 16384),
            'max_output_tokens' => (int) env('MNEMOSYNE_VERIFIER_MAX_TOKENS', 400),
        ],

        // Consecutive provider failures before the circuit opens and
        // queued runs fail fast instead of all waiting on a dead
        // dependency; seconds until a probe is allowed again.
        'circuit' => [
            'failure_threshold' => (int) env('MNEMOSYNE_ANSWER_CIRCUIT_THRESHOLD', 3),
            'cooldown_seconds' => (int) env('MNEMOSYNE_ANSWER_CIRCUIT_COOLDOWN', 120),
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
