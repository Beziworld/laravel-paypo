<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Events;

use Beziworld\PayPo\Data\Notification;

/**
 * Dispatched when the transaction was cancelled (CANCELED).
 */
final readonly class TransactionCanceled
{
    /**
     * @param string|null $connection Name of the connection that received the notification.
     */
    public function __construct(
        public Notification $notification,
        public ?string $connection = null,
    ) {}
}
