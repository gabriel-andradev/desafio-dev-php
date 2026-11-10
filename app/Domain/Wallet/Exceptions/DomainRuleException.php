<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use DomainException;

/**
 * Thrown when a financial business rule or invariant is violated.
 */
class DomainRuleException extends DomainException
{
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
