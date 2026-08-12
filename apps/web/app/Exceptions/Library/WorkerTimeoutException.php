<?php

namespace App\Exceptions\Library;

/**
 * The worker did not answer within the caller's deadline. A subtype of
 * WorkerUnavailableException (same retry semantics) that lets callers
 * report an honest, specific fallback reason (e.g. reranker timeout).
 */
class WorkerTimeoutException extends WorkerUnavailableException {}
