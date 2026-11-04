<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Events;

use Beziworld\PayPo\Data\Notification;

/**
 * Dispatched when PayPo granted the deferred payment (ACCEPTED).
 *
 * The order may now be fulfilled; confirm it afterwards with
 * PayPo::confirm() so that the transaction is settled.
 */
final readonly class TransactionAccepted
{
    /**
     * @param string|null $connection Name of the connection that received the notification.
     */
    public function __construct(
        public Notification $notification,
        public ?string $connection = null,
    ) {}
}
