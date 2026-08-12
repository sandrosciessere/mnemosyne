<?php

namespace App\Services\Answers\Providers;

use App\Services\Answers\EvidencePacket;

/**
 * Provider-independent structured claim generation. Implementations
 * normalize vendor formats into GenerationResult and vendor failures
 * into the App\Exceptions\Answers hierarchy — domain code never sees
 * Ollama/OpenAI response shapes.
 */
interface GenerationProvider
{
    public function generate(string $question, EvidencePacket $packet, ?string $conversationContext, ?string $repairFeedback): GenerationResult;

    public function identity(): ProviderIdentity;
}
