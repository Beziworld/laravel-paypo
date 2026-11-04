<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Data;

use Beziworld\PayPo\Exceptions\ApiException;

/**
 * Response to a transaction registration: the PayPo transaction id and the URL the buyer is redirected to.
 */
final readonly class CreatedTransaction
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $transactionId,
        public string $redirectUrl,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['transactionId'] ?? null;
        $url = $data['redirectUrl'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($url) || $url === '') {
            throw ApiException::unexpectedResponse('the registration response has no transactionId or redirectUrl');
        }

        return new self($id, $url, $data);
    }
}
