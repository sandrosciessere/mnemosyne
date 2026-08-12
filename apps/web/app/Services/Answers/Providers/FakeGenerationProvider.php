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

    /** @var list<array{question: string, packet: EvidencePacket, context: ?string, repair: ?string, language: string, subquestions: array}> */
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

    public function generate(GenerationRequest $request): GenerationResult
    {
        $this->calls[] = [
            'question' => $request->question,
            'packet' => $request->packet,
            'context' => $request->conversationContext,
            'repair' => $request->repairFeedback,
            'language' => $request->languageName,
            'subquestions' => $request->subquestions,
        ];

        if ($this->script === []) {
            throw new \RuntimeException('FakeGenerationProvider has no scripted output left.');
        }

        $next = array_shift($this->script);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $this->validator->validate(
            $next,
            $request->packet,
            (int) config('mnemosyne.answers.generator.max_claims'),
            $request->subquestionKeys(),
        );
    }

    public function identity(): ProviderIdentity
    {
        return new ProviderIdentity('fake', 'deterministic-test-generator', 'test');
    }
}
