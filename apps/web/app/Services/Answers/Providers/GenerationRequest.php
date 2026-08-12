<?php

namespace App\Services\Answers\Providers;

use App\Services\Answers\EvidencePacket;

/**
 * Everything a generation call needs. `languageName` is the required
 * answer language (the user's question language); `subquestions`
 * carries the bounded decomposition for compound questions ([] for
 * simple ones).
 */
class GenerationRequest
{
    /** @param list<array{key: string, text: string}> $subquestions */
    public function __construct(
        public readonly string $question,
        public readonly EvidencePacket $packet,
        public readonly ?string $conversationContext,
        public readonly string $languageName,
        public readonly array $subquestions,
        public readonly ?string $repairFeedback = null,
    ) {}

    /** @return list<string> */
    public function subquestionKeys(): array
    {
        return count($this->subquestions) > 1
            ? array_column($this->subquestions, 'key')
            : [];
    }

    public function withRepairFeedback(string $feedback): self
    {
        return new self(
            $this->question,
            $this->packet,
            $this->conversationContext,
            $this->languageName,
            $this->subquestions,
            $feedback,
        );
    }
}
