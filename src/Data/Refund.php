<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

use Beziworld\PayPo\Support\Amount;
use Beziworld\PayPo\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Refund registered on a transaction, as listed in the extended status response.
 *
 * Refunds made outside the shop (for example in the PayPo panel) have no referenceRefundId.
 */
final readonly class Refund
{
    /**
     * @param int $amount Refunded amount in minor units.
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $referenceRefundId,
        public int $amount,
        public ?CarbonImmutable $created = null,
        public string $currency = 'PLN',
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $currency = 'PLN', string $timezone = 'Europe/Warsaw'): self
    {
        return new self(
            Values::string($data['referenceRefundId'] ?? null),
            Values::int($data['amount'] ?? null) ?? 0,
            Values::date($data['created'] ?? null, $timezone),
            $currency,
            $data,
        );
    }

    /**
     * Refunded amount as a decimal string, e.g. "86.55".
     */
    public function amountDecimal(): string
    {
        return Amount::toDecimal($this->amount);
    }
}
