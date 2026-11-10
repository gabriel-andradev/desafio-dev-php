<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

enum TransactionStatus: string
{
    case COMPLETED = 'COMPLETED';
    case REVERSED = 'REVERSED';
}

