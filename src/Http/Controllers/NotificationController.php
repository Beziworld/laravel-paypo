<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Http\Controllers;

use Beziworld\PayPo\Enums\TransactionStatus;
use Beziworld\PayPo\Events\SettlementReported;
use Beziworld\PayPo\Events\TransactionAccepted;
use Beziworld\PayPo\Events\TransactionCanceled;
use Beziworld\PayPo\Events\TransactionCompleted;
use Beziworld\PayPo\Events\TransactionRejected;
use Beziworld\PayPo\Events\TransactionStatusChanged;
use Beziworld\PayPo\Exceptions\ApiException;
use Beziworld\PayPo\Exceptions\InvalidConfiguration;
use Beziworld\PayPo\Exceptions\InvalidNotification;
use Beziworld\PayPo\PayPoManager;
use Beziworld\PayPo\Support\Signature;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Receives PayPo notifications for the connection named in the URL.
 *
 * The X-PayPo-Signature header is verified with the merchant API key of the
 * connection. Authentic notifications are acknowledged with HTTP 200, which
 * stops redelivery; rejected ones get HTTP 400. When the status cannot be
 * confirmed through the API (verify_status), HTTP 503 makes PayPo retry.
 */
final class NotificationController
{
    public function __construct(
        private readonly PayPoManager $manager,
        private readonly Config $settings,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request, ?string $connection = null): Response
    {
        $name = $connection ?? $this->manager->getDefaultConnection();

        try {
            $client = $this->manager->connection($name);
            $notification = $client->parseNotification(
                $request->getContent(),
                $request->headers->get(Signature::HEADER),
                [$request->getBaseUrl() . $request->getPathInfo(), $request->getPathInfo()],
                (bool) $this->settings->get('paypo.notifications.verify_signature', true),
            );
        } catch (InvalidNotification|InvalidConfiguration $exception) {
            $this->logger->warning('Rejected PayPo notification: ' . $exception->getMessage(), ['connection' => $name, 'ip' => $request->ip()]);

            return new Response('', 400);
        }

        if ((bool) $this->settings->get('paypo.notifications.verify_status', false)) {
            try {
                $notification = $notification->withTransaction($client->transaction($notification->transactionId));
            } catch (ApiException $exception) {
                $this->logger->error('PayPo notification status could not be verified: ' . $exception->getMessage(), [
                    'connection' => $name,
                    'transaction' => $notification->transactionId,
                ]);

                return new Response('', 503);
            }
        }

        if ($notification->isSettlement()) {
            $this->events->dispatch(new SettlementReported($notification, $name));

            return new Response('', 200);
        }

        $this->events->dispatch(new TransactionStatusChanged($notification, $name));

        $event = match ($notification->status) {
            TransactionStatus::Accepted => new TransactionAccepted($notification, $name),
            TransactionStatus::Completed => new TransactionCompleted($notification, $name),
            TransactionStatus::Rejected => new TransactionRejected($notification, $name),
            TransactionStatus::Canceled => new TransactionCanceled($notification, $name),
            default => null,
        };

        if ($event !== null) {
            $this->events->dispatch($event);
        }

        return new Response('', 200);
    }
}
