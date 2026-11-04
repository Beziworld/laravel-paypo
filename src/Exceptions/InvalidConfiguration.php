<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Exceptions;

/**
 * Raised when the package configuration is incomplete or refers to an unknown connection.
 */
final class InvalidConfiguration extends PayPoException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('PayPo "%s" is not configured. Set %s in the environment.', $key, $env));
    }

    public static function unknownConnection(string $name): self
    {
        return new self(sprintf('PayPo connection "%s" is not defined in config/paypo.php.', $name));
    }

    public static function missingUrl(string $key): self
    {
        return new self(sprintf('PayPo transaction requires "%s": pass it in the request or set it in config/paypo.php.', $key));
    }
}
