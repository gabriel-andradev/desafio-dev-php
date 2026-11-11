<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

/**
 * How a transaction can be reversed by a given wallet, as shown in the statement.
 */
enum ReversalState: string
{
    /** The wallet can reverse it right now. */
    case AVAILABLE = 'AVAILABLE';

    /** It would be reversible, but the time window has passed. */
    case EXPIRED = 'EXPIRED';

    /** It can never be reversed by this wallet (already reversed, a reversal entry, not the owner...). */
    case UNAVAILABLE = 'UNAVAILABLE';
}
