<?php

namespace App\Services\Answers\Providers;

/** Persisted provider/model identity for answer reproducibility. */
class ProviderIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $revision,
    ) {}
}
