<?php

namespace App\Exceptions\Library;

use DomainException;

class InvalidTransitionException extends DomainException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
