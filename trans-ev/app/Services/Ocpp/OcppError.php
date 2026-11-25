<?php

namespace App\Services\Ocpp;

use RuntimeException;

/** Becomes a CALLERROR frame: [4, uniqueId, errorCode, description, details]. */
class OcppError extends RuntimeException
{
    public function __construct(public readonly string $ocppCode, string $description = '', public readonly array $details = [])
    {
        parent::__construct($description);
    }
}
