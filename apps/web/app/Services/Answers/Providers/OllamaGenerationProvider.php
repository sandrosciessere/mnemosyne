<?php

namespace App\Services\Answers\Providers;

use App\Exceptions\Answers\ProviderUnavailableException;
use App\Services\Answers\EvidencePacket;

/**
 * Local Ollama structured-generation adapter. One bounded retry on
 * transient unavailability; deterministic invalid-output failures are
 * NOT retried here (the orchestrator owns the single repair attempt,
 * which changes the prompt).
 */
class OllamaGenerationProvider implements GenerationProvider
{
    private ?string $digest = null;

    public function __construct(
        private readonly OllamaClient $client,
        private readonly AnswerPromptBuilder $prompts,
        private readonly GeneratorOutputValidator $validator,
        private readonly array $config,
    ) {}

    public function generate(string $question, EvidencePacket $packet, ?string $conversationContext, ?string $repairFeedback): GenerationResult
    {
        $messages = [
            ['role' => 'system', 'content' => $this->prompts->systemPreamble()],
            ['role' => 'user', 'content' => $this->prompts->evidenceBlock($packet)
                ."\n".$this->prompts->contextBlock($conversationContext)
                .$this->prompts->generatorInstruction($question, $repairFeedback)],
        ];

        $maxClaims = (int) $this->config['max_claims'];
        $raw = $this->callWithRetry($messages, $maxClaims);

        return $this->validator->validate($raw, $packet, $maxClaims);
    }

    public function identity(): ProviderIdentity
    {
        $this->digest ??= $this->client->modelDigest((string) $this->config['model']);

        return new ProviderIdentity('ollama', (string) $this->config['model'], $this->digest);
    }

    private function callWithRetry(array $messages, int $maxClaims): array
    {
        $attempts = 1 + max(0, (int) $this->config['max_retries']);
        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                return $this->client->chatJson(
                    (string) $this->config['model'],
                    $messages,
                    $this->prompts->generatorSchema($maxClaims),
                    (int) $this->config['timeout_seconds'],
                    (int) $this->config['num_ctx'],
                    (int) $this->config['max_output_tokens'],
                    'GENERATOR',
                );
            } catch (ProviderUnavailableException $exception) {
                $lastException = $exception;

                if (! $exception->retryable || $attempt === $attempts - 1) {
                    throw $exception;
                }

                sleep(min(5, 1 + $attempt * 2));
            }
        }

        throw $lastException;
    }
}
