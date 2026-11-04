<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

/**
 * Acknowledgement of a status change or refund, e.g. {"code": 201, "message": "Refund created successfully"}.
 */
final readonly class OperationResult
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public int $code,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, int $httpStatus): self
    {
        return new self(
            is_numeric($data['code'] ?? null) ? (int) $data['code'] : $httpStatus,
            is_string($data['message'] ?? null) ? $data['message'] : null,
            $data,
        );
    }
}
