<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

/**
 * Billing or delivery address of a transaction.
 *
 * PayPo requires street, building and city; the postal code is required
 * on the Polish market. Building and flat numbers are limited to 16
 * characters, the country is an ISO 3166-1 alpha-2 code (PL by default).
 */
final readonly class Address
{
    public function __construct(
        public string $street,
        public string $building,
        public string $city,
        public ?string $zip = null,
        public ?string $flat = null,
        public ?string $county = null,
        public string $country = 'PL',
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            street: self::string($data['street'] ?? null) ?? '',
            building: self::string($data['building'] ?? null) ?? '',
            city: self::string($data['city'] ?? null) ?? '',
            zip: self::string($data['zip'] ?? null),
            flat: self::string($data['flat'] ?? null),
            county: self::string($data['county'] ?? null),
            country: strtoupper(self::string($data['country'] ?? null) ?? 'PL'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'street' => $this->street,
            'building' => $this->building,
            'flat' => $this->flat,
            'zip' => $this->zip,
            'city' => $this->city,
            'county' => $this->county,
            'country' => strtoupper($this->country),
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
