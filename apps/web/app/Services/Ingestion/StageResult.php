<?php

namespace App\Services\Ingestion;

/**
 * Outcome of one stage attempt, normalized across PHP-native stages and
 * worker-delegated stages.
 */
class StageResult
{
    /**
     * @param  'passed'|'passed_with_warnings'|'needs_review'|'failed'  $status
     * @param  list<array{code: string, severity: string, message: string, overrideable: bool, details?: array}>  $issues
     */
    public function __construct(
        public readonly string $status,
        public readonly array $issues = [],
        public readonly array $summary = [],
        public readonly ?string $handlerVersion = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
    ) {}

    public static function passed(array $summary = [], ?string $handlerVersion = null, array $issues = []): self
    {
        $hasWarnings = array_filter($issues, fn ($issue) => ($issue['severity'] ?? '') === 'warning') !== [];

        return new self(
            $hasWarnings ? 'passed_with_warnings' : 'passed',
            $issues,
            $summary,
            $handlerVersion,
        );
    }

    public static function failed(
        string $errorCode,
        string $errorMessage,
        bool $retryable = false,
        array $issues = [],
        ?string $handlerVersion = null,
    ): self {
        return new self(
            'failed',
            $issues,
            [],
            $handlerVersion,
            $errorCode,
            $errorMessage,
            $retryable,
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'passed' || $this->status === 'passed_with_warnings';
    }

    /** @return list<array> issues of a given severity */
    public function issuesBySeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->issues,
            fn ($issue) => ($issue['severity'] ?? '') === $severity,
        ));
    }
}
