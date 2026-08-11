<?php

namespace App\Services\Ingestion;

use App\Exceptions\Library\InvalidTransitionException;
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
            // 401 (token) / 503 (worker fails closed while starting or
            // unconfigured) can be transient during a deploy — retryable.
            throw new WorkerUnavailableException('Worker rejected the call: HTTP '.$response->status());
        }

        if ($response->clientError()) {
            // Any other 4xx (e.g. 422 PATH_INVALID, 404, 405) is a
            // DETERMINISTIC request error, not a transport blip. Retrying it
            // is pointless and hides the real bug behind WORKER_UNAVAILABLE.
            // Surface it as a terminal (non-retryable) stage failure that
            // preserves the worker's own error code.
            $body = $response->json();
            $code = $body['detail']['code']
                ?? (is_string($body['detail'] ?? null) ? 'WORKER_REQUEST_REJECTED' : null)
                ?? $body['issues'][0]['code']
                ?? 'WORKER_HTTP_'.$response->status();
            $message = $body['detail']['message']
                ?? (is_string($body['detail'] ?? null) ? $body['detail'] : null)
                ?? 'Worker rejected the request: HTTP '.$response->status();

            return [
                'status' => 'failed',
                'stage' => $stage,
                'handler_version' => null,
                'issues' => [[
                    'code' => is_string($code) ? $code : 'WORKER_REQUEST_REJECTED',
                    'severity' => 'hard_block',
                    'message' => is_string($message) ? $message : 'Worker rejected the request.',
                    'overrideable' => false,
                ]],
                'result' => [],
            ];
        }

        $envelope = $response->json();

        if (! is_array($envelope) || ! isset($envelope['status'])) {
            throw new WorkerUnavailableException('Worker returned an invalid envelope.');
        }

        return $envelope;
    }

    /**
     * Authenticated GET for non-envelope worker endpoints (e.g. the
     * retrieval model registry).
     *
     * @throws WorkerUnavailableException on transport failure or non-2xx
     */
    public function getJson(string $path): array
    {
        $config = config('mnemosyne.worker');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders(['X-Mnemosyne-Internal-Token' => (string) $config['internal_token']])
                ->connectTimeout($config['connect_timeout_seconds'])
                ->timeout($config['timeout_seconds'])
                ->acceptJson()
                ->get($path);
        } catch (ConnectionException $exception) {
            throw new WorkerUnavailableException(
                'Worker connection failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new WorkerUnavailableException('Worker GET '.$path.' failed: HTTP '.$response->status());
        }

        return $response->json();
    }

    /**
     * Authenticated POST for non-envelope retrieval endpoints
     * (embed/rerank). 4xx here is a deterministic caller bug and is
     * surfaced as-is via exception; 5xx/503/timeout are retryable.
     *
     * @throws WorkerUnavailableException on transport failure or 5xx/503/401
     * @throws InvalidTransitionException on 4xx
     */
    public function postJson(string $path, array $payload): array
    {
        $config = config('mnemosyne.worker');

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withHeaders(['X-Mnemosyne-Internal-Token' => (string) $config['internal_token']])
                ->connectTimeout($config['connect_timeout_seconds'])
                ->timeout($config['timeout_seconds'])
                ->acceptJson()
                ->post($path, $payload);
        } catch (ConnectionException $exception) {
            throw new WorkerUnavailableException(
                'Worker connection failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->serverError() || $response->status() === 503 || $response->unauthorized()) {
            throw new WorkerUnavailableException('Worker '.$path.' unavailable: HTTP '.$response->status());
        }

        if ($response->clientError()) {
            $detail = $response->json('detail');
            $code = is_array($detail) ? ($detail['code'] ?? 'WORKER_REQUEST_REJECTED') : 'WORKER_REQUEST_REJECTED';

            throw new InvalidTransitionException(
                is_string($code) ? $code : 'WORKER_REQUEST_REJECTED',
                'Worker rejected '.$path.': HTTP '.$response->status(),
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new WorkerUnavailableException('Worker '.$path.' returned an invalid body.');
        }

        return $body;
    }
}
