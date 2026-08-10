<?php

namespace App\Services\Ingestion;

use App\Exceptions\Library\WorkerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the internal worker API (/internal/v1). Payloads
 * carry public identifiers and data-root-relative paths only — never
 * absolute filesystem paths, never database ids.
 */
class WorkerClient
{
    /**
     * @return array the worker response envelope
     *
     * @throws WorkerUnavailableException on transport failure or 5xx
     */
    public function stage(string $stage, array $payload): array
    {
        $config = config('mnemosyne.worker');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders(['X-Mnemosyne-Internal-Token' => (string) $config['internal_token']])
                ->connectTimeout($config['connect_timeout_seconds'])
                ->timeout($config['timeout_seconds'])
                ->acceptJson()
                ->post('/internal/v1/epub/'.$stage, $payload);
        } catch (ConnectionException $exception) {
            throw new WorkerUnavailableException(
                'Worker connection failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->serverError()) {
            $envelope = $response->json();

            // A structured 500 envelope (INTERNAL_ERROR) is a worker bug or
            // resource blip, not an EPUB verdict: treat as retryable.
            throw new WorkerUnavailableException(
                'Worker internal error: '.($envelope['issues'][0]['code'] ?? $response->status()),
            );
        }

        if ($response->unauthorized() || $response->status() === 503) {
            throw new WorkerUnavailableException('Worker rejected the call: HTTP '.$response->status());
        }

        $envelope = $response->json();

        if (! is_array($envelope) || ! isset($envelope['status'])) {
            throw new WorkerUnavailableException('Worker returned an invalid envelope.');
        }

        return $envelope;
    }
}
