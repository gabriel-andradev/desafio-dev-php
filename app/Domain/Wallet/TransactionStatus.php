<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

/**
 * Defines the lifecycle states of a ledger transaction.
 */
enum TransactionStatus: string
{
    case COMPLETED = 'COMPLETED';
    case REVERSED = 'REVERSED';
}
