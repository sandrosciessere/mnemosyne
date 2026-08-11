<?php

namespace App\Exceptions\Library;

use RuntimeException;

/**
 * Transport-level failure talking to the AI worker (connection refused,
 * timeout, 5xx). Always retryable — the EPUB itself is not at fault.
 */
class WorkerUnavailableException extends RuntimeException {}
