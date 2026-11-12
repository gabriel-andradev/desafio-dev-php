<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

/**
 * Who started a reversal: the user (with time windows) or an internal compensation (no windows).
 */
enum ReversalSource: string
{
    /** Requested by a participant through the app; subject to the 15 min / 24 h windows. */
    case USER = 'USER';

    /** Internal compensation of a technical inconsistency, run by an operator from the server. */
    case COMPENSATION = 'COMPENSATION';
}
