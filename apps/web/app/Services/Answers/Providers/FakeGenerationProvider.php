<?php

namespace App\Services\Answers\Providers;

use App\Services\Answers\EvidencePacket;

/**
 * Deterministic test double. Tests script it with raw output arrays
 * (validated through the REAL GeneratorOutputValidator so contract
 * tests exercise the actual rejection paths) or with throwables to
 * simulate provider failures. Fails closed in production.
 */
class FakeGenerationProvider implements GenerationProvider
{
    /** @var list<array|\Throwable> */
    private array $script = [];

    /** @var list<array{question: string, packet: EvidencePacket, context: ?string, repair: ?string}> */
    public array $calls = [];

    public function __construct(private readonly GeneratorOutputValidator $validator)
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('FakeGenerationProvider must never run in production.');
        }
    }

    /** Queue one scripted raw output (array) or failure (Throwable). */
    public function scriptOutput(array|\Throwable $output): void
    {
        $this->script[] = $output;
    }

    public function generate(string $question, EvidencePacket $packet, ?string $conversationContext, ?string $repairFeedback): GenerationResult
    {
        $this->calls[] = ['question' => $question, 'packet' => $packet, 'context' => $conversationContext, 'repair' => $repairFeedback];

        if ($this->script === []) {
            throw new \RuntimeException('FakeGenerationProvider has no scripted output left.');
        }

        $next = array_shift($this->script);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $this->validator->validate(
            $next,
            $packet,
            (int) config('mnemosyne.answers.generator.max_claims'),
        );
    }

    public function identity(): ProviderIdentity
    {
        return new ProviderIdentity('fake', 'deterministic-test-generator', 'test');
    }
}
