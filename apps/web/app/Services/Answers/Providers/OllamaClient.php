<?php

namespace App\Services\Answers\Providers;

use App\Exceptions\Answers\ProviderInvalidOutputException;
use App\Exceptions\Answers\ProviderTimeoutException;
use App\Exceptions\Answers\ProviderUnavailableException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Narrow local Ollama chat client (structured outputs via `format`
 * JSON schema). Failure normalization + a bounded cache-backed circuit
 * breaker: after N consecutive transport failures the circuit opens
 * and calls fail fast for a cooldown window instead of every queued
 * answer waiting on a known-dead dependency. No secrets involved: the
 * endpoint is a local unauthenticated service, and nothing from .env
 * is ever logged.
 */
class OllamaClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  string  $errorPrefix  GENERATOR|VERIFIER — the normalized error code prefix
     * @return array decoded JSON content of the assistant message
     */
    public function chatJson(
        string $model,
        array $messages,
        array $schema,
        int $timeoutSeconds,
        int $numCtx,
        int $maxOutputTokens,
        string $errorPrefix,
    ): array {
        $circuitKey = 'answers:circuit:ollama';
        $config = config('mnemosyne.answers.circuit');

        if ((int) Cache::get($circuitKey.':failures', 0) >= (int) $config['failure_threshold']
            && Cache::has($circuitKey.':open')) {
            throw new ProviderUnavailableException(
                $errorPrefix.'_UNAVAILABLE',
                'Provider circuit open after repeated failures; retry after cooldown.',
                retryable: true,
            );
        }

        try {
            $response = Http::baseUrl((string) config('mnemosyne.ollama.base_url'))
                ->connectTimeout(5)
                ->timeout($timeoutSeconds)
                ->acceptJson()
                ->post('/api/chat', [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                    'format' => $schema,
                    'keep_alive' => '30m',
                    'options' => [
                        'temperature' => 0,
                        'num_ctx' => $numCtx,
                        'num_predict' => $maxOutputTokens,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            if ($this->isTimeout($exception)) {
                // A timeout is NOT a circuit failure: the provider is
                // alive but slow for this input size.
                throw new ProviderTimeoutException(
                    $errorPrefix.'_TIMEOUT',
                    'Provider call exceeded '.$timeoutSeconds.'s.',
                    previous: $exception,
                );
            }

            $this->recordFailure($circuitKey, $config);

            throw new ProviderUnavailableException(
                $errorPrefix.'_UNAVAILABLE',
                'Provider connection failed: '.$exception->getMessage(),
                retryable: true,
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            $this->recordFailure($circuitKey, $config);

            throw new ProviderUnavailableException(
                $errorPrefix.'_UNAVAILABLE',
                'Provider returned HTTP '.$response->status(),
                retryable: $response->status() >= 500,
            );
        }

        Cache::forget($circuitKey.':failures');

        $content = $response->json('message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new ProviderInvalidOutputException(
                $errorPrefix.'_INVALID_OUTPUT',
                'Provider returned an empty completion.',
            );
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new ProviderInvalidOutputException(
                $errorPrefix.'_INVALID_OUTPUT',
                'Provider completion is not valid JSON.',
            );
        }

        return $decoded;
    }

    public function modelDigest(string $model): ?string
    {
        try {
            $response = Http::baseUrl((string) config('mnemosyne.ollama.base_url'))
                ->connectTimeout(5)->timeout(15)->acceptJson()
                ->post('/api/show', ['model' => $model]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $digest = $response->json('details.digest') ?? $response->json('digest');

        return is_string($digest) ? mb_substr($digest, 0, 64) : null;
    }

    private function recordFailure(string $circuitKey, array $config): void
    {
        $failures = (int) Cache::increment($circuitKey.':failures');

        if ($failures >= (int) $config['failure_threshold']) {
            Cache::put($circuitKey.':open', true, (int) $config['cooldown_seconds']);
        }
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();

        if ($previous instanceof ConnectException
            && ($previous->getHandlerContext()['errno'] ?? null) === 28) {
            return true;
        }

        return str_contains($exception->getMessage(), 'timed out')
            || str_contains($exception->getMessage(), 'cURL error 28');
    }
}
