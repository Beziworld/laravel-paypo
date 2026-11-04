<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Enums;

/**
 * Settlement status of a transaction with the merchant.
 */
enum SettlementStatus: string
{
    case New = 'NEW';

    /** Order completion confirmed, ready to be settled. */
    case Confirmed = 'CONFIRMED';

    /** Settled with the merchant. */
    case Paid = 'PAID';
}
