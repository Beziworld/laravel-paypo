<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Events;

use Beziworld\PayPo\Data\Notification;

/**
 * Dispatched when PayPo generated the wire transfer specification of a transaction.
 *
 * The notification carries settlementStatus and message.
 */
final readonly class SettlementReported
{
    /**
     * @param string|null $connection Name of the connection that received the notification.
     */
    public function __construct(
        public Notification $notification,
        public ?string $connection = null,
    ) {}
}
