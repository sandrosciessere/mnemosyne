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

];
