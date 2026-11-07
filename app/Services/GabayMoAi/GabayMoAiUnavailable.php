<?php

namespace App\Services\GabayMoAi;

use RuntimeException;

class GabayMoAiUnavailable extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
