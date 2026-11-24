<?php

namespace App\Services\Payments;

use RuntimeException;

class GatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly array $payload = [])
    {
        parent::__construct($message);
    }
}
