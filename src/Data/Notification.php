<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

use Beziworld\PayPo\Enums\SettlementStatus;
use Beziworld\PayPo\Enums\TransactionStatus;
use Beziworld\PayPo\Exceptions\InvalidNotification;
use Beziworld\PayPo\Support\Amount;
use Beziworld\PayPo\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Notification sent by PayPo to notifyUrl.
 *
 * Two kinds are documented: a change of the transaction status or amount,
 * and the generation of the wire transfer specification, which also carries
 * settlementStatus and message.
 */
final readonly class Notification
{
    /**
     * @param int $amount Current transaction amount in minor units.
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $transactionId,
        public TransactionStatus $status,
        public int $amount,
        public ?string $referenceId = null,
        public ?string $merchantId = null,
        public ?string $shopId = null,
        public ?string $transactionUrl = null,
        public ?CarbonImmutable $lastUpdate = null,
        public ?SettlementStatus $settlementStatus = null,
        public ?string $message = null,
        public string $currency = 'PLN',
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidNotification When the transaction id or status is missing or unknown.
     */
    public static function fromArray(array $data, string $currency = 'PLN', string $timezone = 'Europe/Warsaw'): self
    {
        $id = Values::string($data['transactionId'] ?? null);
        $status = TransactionStatus::tryFrom(Values::string($data['transactionStatus'] ?? null) ?? '');

        if ($id === null || $status === null) {
            throw InvalidNotification::malformed();
        }

        return new self(
            transactionId: $id,
            status: $status,
            amount: Values::int($data['amount'] ?? null) ?? 0,
            referenceId: Values::string($data['referenceId'] ?? null),
            merchantId: Values::string($data['merchantId'] ?? null),
            shopId: Values::string($data['shopId'] ?? null),
            transactionUrl: Values::string($data['transactionUrl'] ?? null),
            lastUpdate: Values::date($data['lastUpdate'] ?? null, $timezone),
            settlementStatus: SettlementStatus::tryFrom(Values::string($data['settlementStatus'] ?? null) ?? ''),
            message: Values::string($data['message'] ?? null),
            currency: $currency,
            raw: $data,
        );
    }

    /**
     * Whether this is the notification about the generated wire transfer specification.
     */
    public function isSettlement(): bool
    {
        return array_key_exists('settlementStatus', $this->raw);
    }

    public function amountDecimal(): string
    {
        return Amount::toDecimal($this->amount);
    }

    /**
     * Copy carrying the status and amount reported by the status endpoint.
     */
    public function withTransaction(Transaction $transaction): self
    {
        return new self(
            transactionId: $this->transactionId,
            status: $transaction->status,
            amount: $transaction->amount,
            referenceId: $this->referenceId ?? $transaction->referenceId,
            merchantId: $this->merchantId ?? $transaction->merchantId,
            shopId: $this->shopId,
            transactionUrl: $this->transactionUrl,
            lastUpdate: $transaction->lastUpdate ?? $this->lastUpdate,
            settlementStatus: $transaction->settlementStatus ?? $this->settlementStatus,
            message: $this->message,
            currency: $this->currency,
            raw: $this->raw,
        );
    }
}
