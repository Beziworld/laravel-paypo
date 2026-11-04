<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Events;

use Beziworld\PayPo\Data\Notification;

/**
 * Dispatched for every authentic status or amount change notification.
 *
 * PayPo repeats notifications until it receives HTTP 2xx and statuses may
 * arrive out of order, so listeners must be idempotent and should compare
 * TransactionStatus::rank() with the stored status.
 */
final readonly class TransactionStatusChanged
{
    /**
     * @param string|null $connection Name of the connection that received the notification.
     */
    public function __construct(
        public Notification $notification,
        public ?string $connection = null,
    ) {}
}
