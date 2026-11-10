<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use DomainException;

class DomainRuleException extends DomainException
{
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}

