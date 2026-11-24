<?php

namespace App\Exceptions;

use RuntimeException;

final class ForwardingFailure extends RuntimeException
{
    public function __construct(string $reason, public readonly string $outcome = 'failed')
    {
        parent::__construct($reason);
    }
}
