<?php

namespace App\Exceptions\Library;

use RuntimeException;

class StorageException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
