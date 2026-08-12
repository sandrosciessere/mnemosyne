<?php

namespace App\Services\Answers\Providers;

/**
 * Resolves the configured generation/verifier providers. `ollama` is
 * the only real provider in M3; `fake` resolves the container-bound
 * deterministic doubles (registered as singletons by tests). Provider
 * selection is application configuration — never a user request field.
 */
class AnswerProviderFactory
{
    public function generator(): GenerationProvider
    {
        $config = config('mnemosyne.answers.generator');

        return match ($config['provider']) {
            'ollama' => new OllamaGenerationProvider(
                app(OllamaClient::class),
                app(AnswerPromptBuilder::class),
                new GeneratorOutputValidator,
                $config,
            ),
            'fake' => app(FakeGenerationProvider::class),
            default => throw new \RuntimeException('Unknown generation provider: '.$config['provider']),
        };
    }

    public function verifier(): VerifierProvider
    {
        $config = config('mnemosyne.answers.verifier');

        return match ($config['provider']) {
            'ollama' => new OllamaVerifierProvider(
                app(OllamaClient::class),
                app(AnswerPromptBuilder::class),
                new VerifierOutputValidator,
                $config,
            ),
            'fake' => app(FakeVerifierProvider::class),
            default => throw new \RuntimeException('Unknown verifier provider: '.$config['provider']),
        };
    }
}
