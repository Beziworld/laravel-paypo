<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Exceptions;

/**
 * Raised when an incoming notification is not authentic or cannot be read.
 */
final class InvalidNotification extends PayPoException
{
    public static function missingSignature(): self
    {
        return new self('PayPo notification has no X-PayPo-Signature header.');
    }

    public static function invalidSignature(): self
    {
        return new self('PayPo notification signature does not match.');
    }

    public static function missingKey(): self
    {
        return new self('PayPo merchant API key is not configured, the notification signature cannot be verified. Set PAYPO_MERCHANT_API_KEY.');
    }

    public static function malformed(): self
    {
        return new self('PayPo notification body is not a valid notification JSON object.');
    }

    public static function merchantMismatch(): self
    {
        return new self('PayPo notification belongs to another merchant.');
    }
}
