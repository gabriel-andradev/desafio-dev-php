<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

enum TransactionType: string
{
    case DEPOSIT = 'DEPOSIT';
    case TRANSFER = 'TRANSFER';
    case REVERSAL = 'REVERSAL';
}

