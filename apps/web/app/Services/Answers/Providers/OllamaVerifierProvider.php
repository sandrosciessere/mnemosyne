<?php

namespace App\Services\Answers\Providers;

use App\Exceptions\Answers\ProviderUnavailableException;
use App\Services\Answers\EvidencePacket;

/**
 * Local Ollama verifier adapter. Shares the system preamble + evidence
 * block layout with the generator so consecutive verifier calls reuse
 * the provider's prompt KV cache; the claim under verification is
 * appended last. Independently configured from the generator.
 */
class OllamaVerifierProvider implements VerifierProvider
{
    private ?string $digest = null;

    public function __construct(
        private readonly OllamaClient $client,
        private readonly AnswerPromptBuilder $prompts,
        private readonly VerifierOutputValidator $validator,
        private readonly array $config,
    ) {}

    public function verify(string $question, EvidencePacket $packet, GeneratedClaimDraft $claim, ?string $feedback = null, ?string $subquestionText = null): VerificationResult
    {
        $instruction = $this->prompts->verifierInstruction($question, $claim, $subquestionText);

        if ($feedback !== null) {
            $instruction .= "\n\nAPPLICATION CHECK FAILED on your previous verdict: ".$feedback;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->prompts->systemPreamble()],
            ['role' => 'user', 'content' => $this->prompts->evidenceBlock($packet)
                ."\n".$instruction],
        ];

        $attempts = 1 + max(0, (int) $this->config['max_retries']);
        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $raw = $this->client->chatJson(
                    (string) $this->config['model'],
                    $messages,
                    $this->prompts->verifierSchema(),
                    (int) $this->config['timeout_seconds'],
                    (int) $this->config['num_ctx'],
                    (int) $this->config['max_output_tokens'],
                    'VERIFIER',
                );

                return $this->validator->validate($raw, $packet, $claim);
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

    public function identity(): ProviderIdentity
    {
        $this->digest ??= $this->client->modelDigest((string) $this->config['model']);

        return new ProviderIdentity('ollama', (string) $this->config['model'], $this->digest);
    }
}
