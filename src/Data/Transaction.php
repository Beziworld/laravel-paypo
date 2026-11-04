<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

use Beziworld\PayPo\Enums\SettlementStatus;
use Beziworld\PayPo\Enums\TransactionStatus;
use Beziworld\PayPo\Exceptions\ApiException;
use Beziworld\PayPo\Support\Amount;
use Beziworld\PayPo\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Current state of a transaction (GET /transactions/{id}).
 *
 * The amount is the current order value in minor units; it decreases with
 * refunds. "refunds" is null when PayPo has not enabled the extended
 * response for the merchant, which is different from an empty list.
 */
final readonly class Transaction
{
    /**
     * @param list<Refund>|null $refunds
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $transactionId,
        public TransactionStatus $status,
        public int $amount,
        public ?string $referenceId = null,
        public ?string $merchantId = null,
        public ?SettlementStatus $settlementStatus = null,
        public ?CarbonImmutable $lastUpdate = null,
        public ?array $refunds = null,
        public string $currency = 'PLN',
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $currency = 'PLN', string $timezone = 'Europe/Warsaw'): self
    {
        $id = Values::string($data['transactionId'] ?? null);
        $status = TransactionStatus::tryFrom(Values::string($data['transactionStatus'] ?? null) ?? '');

        if ($id === null || $status === null) {
            throw ApiException::unexpectedResponse('the transaction has no transactionId or a known transactionStatus');
        }

        $refunds = null;

        if (is_array($data['refunds'] ?? null)) {
            $refunds = [];

            foreach ($data['refunds'] as $refund) {
                if (is_array($refund)) {
                    $refunds[] = Refund::fromArray($refund, $currency, $timezone);
                }
            }
        }

        return new self(
            transactionId: $id,
            status: $status,
            amount: Values::int($data['amount'] ?? null) ?? 0,
            referenceId: Values::string($data['referenceId'] ?? null),
            merchantId: Values::string($data['merchantId'] ?? null),
            settlementStatus: SettlementStatus::tryFrom(Values::string($data['settlementStatus'] ?? null) ?? ''),
            lastUpdate: Values::date($data['lastUpdate'] ?? null, $timezone),
            refunds: $refunds,
            currency: $currency,
            raw: $data,
        );
    }

    /**
     * Current order value as a decimal string, e.g. "249.00".
     */
    public function amountDecimal(): string
    {
        return Amount::toDecimal($this->amount);
    }

    /**
     * Sum of the listed refunds in minor units; null when the extended response is not enabled.
     */
    public function refundedAmount(): ?int
    {
        return $this->refunds === null ? null : array_sum(array_map(static fn(Refund $refund): int => $refund->amount, $this->refunds));
    }

    /**
     * Refund registered with the given referenceRefundId, if listed.
     */
    public function findRefund(string $referenceRefundId): ?Refund
    {
        foreach ($this->refunds ?? [] as $refund) {
            if ($refund->referenceRefundId === $referenceRefundId) {
                return $refund;
            }
        }

        return null;
    }
}
