<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Enums;

/**
 * Status of a PayPo transaction.
 *
 * Statuses form a hierarchy: COMPLETED implies NEW, PENDING and ACCEPTED,
 * while REJECTED and CANCELED imply NEW and PENDING. A REJECTED transaction
 * may still become ACCEPTED when the buyer returns to the payment link.
 */
enum TransactionStatus: string
{
    /** Registered, the buyer has not reached PayPo yet. Never sent in notifications. */
    case New = 'NEW';

    /** The buyer's identity or credibility is being verified. */
    case Pending = 'PENDING';

    /** Deferred payment granted; the order may be fulfilled and then confirmed. */
    case Accepted = 'ACCEPTED';

    /** Confirmed by the merchant, ready to be settled. */
    case Completed = 'COMPLETED';

    /** Deferred payment refused. */
    case Rejected = 'REJECTED';

    /** Cancelled by the merchant or by PayPo. */
    case Canceled = 'CANCELED';

    /**
     * Whether the buyer has been granted the deferred payment.
     */
    public function isAccepted(): bool
    {
        return $this === self::Accepted || $this === self::Completed;
    }

    /**
     * Whether no further status change can be expected without merchant action.
     */
    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }

    /**
     * Whether the transaction may still be cancelled (every status before COMPLETED).
     */
    public function isCancelable(): bool
    {
        return $this !== self::Completed && $this !== self::Canceled;
    }

    /**
     * Whether refunds may be registered (ACCEPTED or COMPLETED).
     */
    public function isRefundable(): bool
    {
        return $this->isAccepted();
    }

    /**
     * Position in the documented status hierarchy, used to ignore stale notifications.
     */
    public function rank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Pending => 1,
            self::Accepted, self::Rejected => 2,
            self::Completed, self::Canceled => 3,
        };
    }
}
