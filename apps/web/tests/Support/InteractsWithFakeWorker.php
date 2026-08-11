<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Shared helpers for feature tests that drive the ingestion pipeline
 * against a contract-faithful faked worker on the database queue.
 */
trait InteractsWithFakeWorker
{
    protected function passedEnvelope(string $stage, array $result, array $issues = []): array
    {
        $status = 'passed';
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'warning') {
                $status = 'passed_with_warnings';
            }
        }

        return [
            'status' => $status,
            'stage' => $stage,
            'handler_version' => '1.1.0',
            'duration_ms' => 4,
            'issues' => $issues,
            'result' => $result,
        ];
    }

    protected function happyEnvelopeFor(string $stage, array $validateIssues = []): array
    {
        return match ($stage) {
            'validate' => $this->passedEnvelope('validate', [
                'epub_version' => '3.0', 'spine_count' => 2,
                'zip' => ['entry_count' => 6, 'total_uncompressed_bytes' => 2048],
            ], $validateIssues),
            'parse' => $this->passedEnvelope('parse', [
                'metadata' => [
                    'title' => 'Pause Test Book',
                    'creators' => [['name' => 'Pause Author', 'roles' => ['aut']]],
                    'languages' => ['en'],
                    'identifiers' => [],
                ],
            ]),
            'normalize' => $this->passedEnvelope('normalize', ['spine_documents' => 2, 'nodes' => 8, 'chars' => 400]),
            'structure' => $this->passedEnvelope('structure', [
                'content_sha256' => hash('sha256', static::class),
                'fingerprint_version' => '1',
                'counts' => ['sections' => 2, 'toc_entries' => 2, 'nodes' => 8, 'chars' => 400],
            ]),
            default => $this->passedEnvelope($stage, []),
        };
    }

    protected function fakeHappyWorker(array $validateIssues = []): void
    {
        Http::fake(function ($request) use ($validateIssues) {
            $stage = basename(parse_url($request->url(), PHP_URL_PATH));

            return Http::response($this->happyEnvelopeFor($stage, $validateIssues));
        });
    }

    /** Run exactly one queued ingestion job (or none if the queue is empty). */
    protected function workOneJob(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => 'ingestion-high,ingestion-normal,ingestion-low',
            '--sleep' => 0,
            '--tries' => 1,
        ]);
    }

    protected function drainQueue(int $max = 60): void
    {
        for ($iteration = 0; $iteration < $max; $iteration++) {
            if ((int) DB::table('jobs')->count() === 0) {
                return;
            }

            $this->workOneJob();
        }

        $this->fail("Queue did not drain within {$max} jobs.");
    }

    protected function pendingJobs(): int
    {
        return (int) DB::table('jobs')->count();
    }
}
