<?php

declare(strict_types=1);

namespace Beziworld\PayPo;

use Beziworld\PayPo\Auth\AccessTokenProvider;
use Beziworld\PayPo\Data\CreatedTransaction;
use Beziworld\PayPo\Data\Notification;
use Beziworld\PayPo\Data\OperationResult;
use Beziworld\PayPo\Data\Transaction;
use Beziworld\PayPo\Data\TransactionRequest;
use Beziworld\PayPo\Exceptions\ApiException;
use Beziworld\PayPo\Exceptions\InvalidNotification;
use Beziworld\PayPo\Support\Signature;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use JsonException;

/**
 * Client of the PayPo REST API v3 for one merchant account.
 *
 * Writes (registration, confirmation, cancellation, refunds) are sent once;
 * the only repetition is a single resend after HTTP 401, when the cached
 * OAuth token expired and the request was therefore not processed. Status
 * reads are additionally retried on connection errors and HTTP 5xx.
 */
class PayPoClient
{
    /**
     * @param (Closure(PayPoConfig): ?string)|null $notifyUrlResolver Builds the default notification URL of the connection.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AccessTokenProvider $tokens,
        private readonly PayPoConfig $config,
        private readonly ?Closure $notifyUrlResolver = null,
    ) {}

    public function config(): PayPoConfig
    {
        return $this->config;
    }

    public function connectionName(): ?string
    {
        return $this->config->name;
    }

    /**
     * Notification URL sent with new transactions: the configured one or the package route of the connection.
     */
    public function notifyUrl(): ?string
    {
        return $this->config->notifyUrl ?? ($this->notifyUrlResolver)?->__invoke($this->config);
    }

    /**
     * Registers a transaction; redirect the buyer to the returned redirectUrl.
     */
    public function createTransaction(TransactionRequest $request): CreatedTransaction
    {
        return CreatedTransaction::fromArray($this->call('POST', '/transactions', $request->toArray($this->config, $this->notifyUrl()))[0]);
    }

    /**
     * Current status, amount, settlement status and (when enabled for the merchant) refunds of a transaction.
     */
    public function transaction(string $transactionId): Transaction
    {
        [$payload] = $this->call('GET', '/transactions/' . $this->id($transactionId), retry: true);

        return Transaction::fromArray($payload, $this->config->currency, $this->config->timezone);
    }

    /**
     * Confirms completion of the order (status COMPLETED), which makes the transaction ready to be settled.
     */
    public function confirm(string $transactionId): OperationResult
    {
        return $this->changeStatus($transactionId, 'COMPLETED');
    }

    /**
     * Cancels a payment or draft that is not completed yet; PayPo answers HTTP 409 for COMPLETED transactions.
     */
    public function cancel(string $transactionId): OperationResult
    {
        return $this->changeStatus($transactionId, 'CANCELED');
    }

    /**
     * Registers a full or partial refund of an ACCEPTED or COMPLETED transaction.
     *
     * Successive partial refunds may not exceed the order value; a refund of
     * an ACCEPTED transaction completes it automatically.
     *
     * @param int $amount Refund amount in minor units.
     * @param string|null $referenceRefundId Refund identifier of the shop (max. 68 characters); the only way to match the refund later.
     */
    public function refund(string $transactionId, int $amount, ?string $referenceRefundId = null): OperationResult
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('PayPo refund amount must be greater than zero.');
        }

        if ($referenceRefundId !== null && mb_strlen($referenceRefundId) > 68) {
            throw new InvalidArgumentException('PayPo referenceRefundId may have at most 68 characters.');
        }

        $body = ['amount' => $amount];

        if ($referenceRefundId !== null && $referenceRefundId !== '') {
            $body['referenceRefundId'] = $referenceRefundId;
        }

        [$payload, $status] = $this->call('POST', '/transactions/' . $this->id($transactionId) . '/refunds', $body);

        return OperationResult::fromArray($payload, $status);
    }

    /**
     * Verifies the X-PayPo-Signature header and parses a notification.
     *
     * @param string|list<string> $paths URL path(s) of the notification endpoint as known to PayPo, e.g. "/paypo/notification".
     *
     * @throws InvalidNotification When the signature is missing or invalid, the merchant differs or the body is malformed.
     */
    public function parseNotification(string $rawBody, ?string $signature, string|array $paths, bool $verifySignature = true): Notification
    {
        if ($verifySignature) {
            Signature::verify(
                $rawBody,
                $signature,
                is_array($paths) ? $paths : [$paths],
                $this->config->merchantApiKey ?? throw InvalidNotification::missingKey(),
            );
        }

        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidNotification::malformed();
        }

        if (! is_array($payload)) {
            throw InvalidNotification::malformed();
        }

        $notification = Notification::fromArray($payload, $this->config->currency, $this->config->timezone);

        if ($this->config->merchantId !== null && $notification->merchantId !== null && $notification->merchantId !== $this->config->merchantId) {
            throw InvalidNotification::merchantMismatch();
        }

        return $notification;
    }

    private function changeStatus(string $transactionId, string $status): OperationResult
    {
        [$payload, $code] = $this->call('PATCH', '/transactions/' . $this->id($transactionId), ['status' => $status]);

        return OperationResult::fromArray($payload, $code);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: array<array-key, mixed>, 1: int}
     */
    private function call(string $method, string $path, ?array $body = null, bool $retry = false): array
    {
        $attempts = $retry ? $this->config->retries + 1 : 1;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->send($method, $path, $body);

                if ($response->status() === 401) {
                    $this->tokens->forget();
                    $response = $this->send($method, $path, $body);
                }
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    $this->pause();

                    continue;
                }

                throw ApiException::transport($exception);
            }

            if ($response->serverError() && $attempt < $attempts) {
                $this->pause();

                continue;
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];

            if ($response->failed()) {
                $retryAfter = $response->header('Retry-After');

                throw ApiException::fromResponse($payload, $response->status(), is_numeric($retryAfter) ? (int) $retryAfter : null);
            }

            return [$payload, $response->status()];
        }
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @throws ConnectionException
     */
    private function send(string $method, string $path, ?array $body): Response
    {
        $request = $this->http
            ->createPendingRequest()
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->timeout($this->config->timeout)
            ->withUserAgent('beziworld-laravel-paypo/1.0');

        return $request->send($method, $this->config->apiUrl() . $path, $body === null ? [] : ['json' => $body]);
    }

    private function pause(): void
    {
        if ($this->config->retryDelay > 0) {
            Sleep::usleep($this->config->retryDelay * 1000);
        }
    }

    private function id(string $transactionId): string
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            throw new InvalidArgumentException('PayPo transaction id must not be empty.');
        }

        return rawurlencode($transactionId);
    }
}
