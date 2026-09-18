<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ApiException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message, public readonly int $errorCode, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}
