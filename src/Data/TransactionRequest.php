<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

use Beziworld\PayPo\Enums\Shipment;
use Beziworld\PayPo\Exceptions\InvalidConfiguration;
use Beziworld\PayPo\PayPoConfig;
use InvalidArgumentException;

/**
 * Data of a new PayPo transaction (POST /transactions).
 *
 * The amount is given in minor units (grosz); use Support\Amount::toMinor()
 * to convert a decimal value. The delivery address defaults to the billing
 * address. URLs fall back to the configuration of the connection.
 */
final readonly class TransactionRequest
{
    /**
     * @param string $referenceId Order identifier in the shop, also used to identify wire transfers.
     * @param int $amount Order amount in minor units, greater than zero.
     * @param string|null $id Optional transaction UUID chosen by the shop.
     * @param string|null $providerId Transaction id in the payment operator's system, when integrated by an operator.
     */
    public function __construct(
        public string $referenceId,
        public int $amount,
        public Customer $customer,
        public Address $billingAddress,
        public ?Address $shippingAddress = null,
        public ?string $description = null,
        public ?Shipment $shipment = null,
        public ?string $returnUrl = null,
        public ?string $notifyUrl = null,
        public ?string $cancelUrl = null,
        public ?string $id = null,
        public ?string $shopId = null,
        public ?string $providerId = null,
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('PayPo transaction amount must be greater than zero.');
        }

        if (trim($referenceId) === '') {
            throw new InvalidArgumentException('PayPo transaction requires a referenceId.');
        }
    }

    /**
     * Request body with defaults taken from the connection configuration.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidConfiguration When the return or notification URL cannot be determined.
     */
    public function toArray(PayPoConfig $config, ?string $defaultNotifyUrl = null): array
    {
        $order = array_filter([
            'referenceId' => $this->referenceId,
            'providerId' => $this->providerId,
            'description' => $this->description,
            'amount' => $this->amount,
            'shipment' => $this->shipment?->value,
            'billingAddress' => $this->billingAddress->toArray(),
            'shippingAddress' => ($this->shippingAddress ?? $this->billingAddress)->toArray(),
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $configuration = array_filter([
            'returnUrl' => $this->returnUrl ?? $config->returnUrl ?? throw InvalidConfiguration::missingUrl('returnUrl'),
            'notifyUrl' => $this->notifyUrl ?? $config->notifyUrl ?? $defaultNotifyUrl ?? throw InvalidConfiguration::missingUrl('notifyUrl'),
            'cancelUrl' => $this->cancelUrl ?? $config->cancelUrl,
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        return array_filter([
            'id' => $this->id,
            'shopId' => $this->shopId ?? $config->shopId,
            'order' => $order,
            'customer' => $this->customer->toArray(),
            'configuration' => $configuration,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
