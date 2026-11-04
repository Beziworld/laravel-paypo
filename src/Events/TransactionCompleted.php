<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Events;

use Beziworld\PayPo\Data\Notification;

/**
 * Dispatched when the transaction was confirmed and is ready to be settled (COMPLETED).
 */
final readonly class TransactionCompleted
{
    /**
     * @param string|null $connection Name of the connection that received the notification.
     */
    public function __construct(
        public Notification $notification,
        public ?string $connection = null,
    ) {}
}
