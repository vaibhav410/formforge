<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AiServiceException extends RuntimeException
{
    /**
     * @param  array<int, array>  $attempts  per-round-trip telemetry, when the
     *     service got far enough to report any
     */
    public function __construct(string $message, public readonly array $attempts = [])
    {
        parent::__construct($message);
    }
}
