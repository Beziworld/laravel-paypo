<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Exceptions;

use Throwable;

/**
 * Raised when PayPo rejects a request or cannot be reached.
 *
 * httpStatus() is the main result indicator documented by PayPo
 * (400 validation, 401 authentication, 403, 404, 405, 409 conflict,
 * 503 unavailable). errors() lists the validation errors of HTTP 400
 * responses as path/message pairs.
 */
final class ApiException extends PayPoException
{
    /**
     * @param list<array{path: ?string, message: string}> $errors
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
        private readonly array $payload = [],
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(array $payload, int $httpStatus, ?int $retryAfter = null): self
    {
        $message = $payload['message'] ?? $payload['error_description'] ?? null;
        $message = is_string($message) && $message !== '' ? $message : sprintf('request failed with HTTP status %d', $httpStatus);
        $errors = self::parseErrors($payload['errors'] ?? null);

        if ($errors !== []) {
            $message .= ' (' . implode('; ', array_map(
                static fn(array $error): string => ($error['path'] !== null ? $error['path'] . ': ' : '') . $error['message'],
                $errors,
            )) . ')';
        }

        if ($httpStatus === 429) {
            $message = 'PayPo rate limit exceeded' . ($retryAfter !== null ? sprintf(', retry after %d seconds', $retryAfter) : '');
        }

        return new self(sprintf('PayPo error (HTTP %d): %s', $httpStatus, $message), $httpStatus, $errors, $payload, $retryAfter);
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not connect to PayPo: ' . $previous->getMessage(), previous: $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function authenticationFailed(int $httpStatus, array $payload = []): self
    {
        return new self('PayPo rejected the OAuth client credentials.', $httpStatus, [], $payload);
    }

    public static function unexpectedResponse(string $what): self
    {
        return new self(sprintf('PayPo returned an unexpected response: %s.', $what));
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return list<array{path: ?string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Seconds from the Retry-After header of an HTTP 429 response.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isConflict(): bool
    {
        return $this->httpStatus === 409;
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    /**
     * Accepts both a list of {path, message} objects and a path => message map.
     *
     * @return list<array{path: ?string, message: string}>
     */
    private static function parseErrors(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        $list = [];

        foreach ($errors as $key => $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $list[] = ['path' => is_string($error['path'] ?? null) ? $error['path'] : null, 'message' => $error['message']];
            } elseif (is_string($error)) {
                $list[] = ['path' => is_string($key) ? $key : null, 'message' => $error];
            }
        }

        return $list;
    }
}
