<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Events;

use Beziworld\PayPo\Data\Notification;

/**
 * Dispatched when PayPo refused the deferred payment (REJECTED).
 *
 * A rejected transaction may still become ACCEPTED when the buyer returns
 * to the payment link.
 */
final readonly class TransactionRejected
{
    /**
     * @param string|null $connection Name of the connection that received the notification.
     */
    public function __construct(
        public Notification $notification,
        public ?string $connection = null,
    ) {}
}
