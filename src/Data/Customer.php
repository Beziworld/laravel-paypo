<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

use DateTimeInterface;

/**
 * Buyer data sent with a new transaction.
 *
 * The phone number should carry the country prefix; without it PayPo
 * assumes the prefix of the market. The registration and transaction
 * history fields are documented as dedicated to the Romanian market and
 * are sent only when provided.
 */
final readonly class Customer
{
    /**
     * @param int|null $sumOfTransactions Sum of previous transactions in minor units.
     */
    public function __construct(
        public string $name,
        public string $surname,
        public string $email,
        public ?string $phone = null,
        public ?bool $isRegistered = null,
        public ?DateTimeInterface $registeredAt = null,
        public ?int $numberOfTransactions = null,
        public ?int $sumOfTransactions = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $customer = [
            'name' => $this->name,
            'surname' => $this->surname,
            'email' => $this->email,
        ];

        if ($this->phone !== null && $this->phone !== '') {
            $customer['phone'] = $this->phone;
        }

        $registration = array_filter([
            'isRegistered' => $this->isRegistered,
            'dateOfRegistration' => $this->registeredAt?->format('Y-m-d'),
        ], static fn(mixed $value): bool => $value !== null);

        if ($registration !== []) {
            $customer['registrationInfo'] = $registration;
        }

        $history = array_filter([
            'numberOfTransactions' => $this->numberOfTransactions,
            'sumOfTransactions' => $this->sumOfTransactions,
        ], static fn(?int $value): bool => $value !== null);

        if ($history !== []) {
            $customer['transactionsInfo'] = $history;
        }

        return $customer;
    }
}
