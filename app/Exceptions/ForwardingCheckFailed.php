<?php

namespace App\Exceptions;

use RuntimeException;

final class ForwardingCheckFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $temporary = false)
    {
        parent::__construct($reason);
    }
}
