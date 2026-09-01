<?php

namespace App\Pos\Support;

class AppError extends \RuntimeException
{
    public function __construct(
        public string $errCode,
        string $message,
        public mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
