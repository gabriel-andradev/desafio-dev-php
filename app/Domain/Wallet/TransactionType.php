<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

/**
 * Defines the supported ledger transaction categories.
 */
enum TransactionType: string
{
    case DEPOSIT = 'DEPOSIT';
    case TRANSFER = 'TRANSFER';
    case REVERSAL = 'REVERSAL';
}
