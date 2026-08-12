<?php

namespace App\Services\Answers\Providers;

/**
 * Provider-independent structured claim generation. Implementations
 * normalize vendor formats into GenerationResult and vendor failures
 * into the App\Exceptions\Answers hierarchy — domain code never sees
 * Ollama/OpenAI response shapes.
 */
interface GenerationProvider
{
    public function generate(GenerationRequest $request): GenerationResult;

    public function identity(): ProviderIdentity;
}
