<?php

namespace App\Exceptions\Answers;

use RuntimeException;

/**
 * Base class for normalized provider-independent failures. The code is
 * a bounded machine error code (persisted on the run, e.g.
 * GENERATOR_TIMEOUT); message text never contains secrets.
 */
class AnswerProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
