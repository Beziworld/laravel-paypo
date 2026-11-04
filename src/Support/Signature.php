<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Support;

use Beziworld\PayPo\Exceptions\InvalidNotification;

/**
 * Computes and verifies the X-PayPo-Signature header of notifications.
 *
 * The documented algorithm is base64(HMAC-SHA256(METHOD + "+" + URL_PATH
 * + "+" + JSON, merchantApiKey)), where URL_PATH is the path of the
 * notification URL without the domain. The reference implementation
 * re-encodes the payload with JSON_UNESCAPED_SLASHES and
 * JSON_UNESCAPED_UNICODE, so the signature is checked both against the
 * raw body and against that canonical encoding.
 */
final class Signature
{
    public const string HEADER = 'X-PayPo-Signature';

    public static function compute(string $method, string $path, string $json, #[\SensitiveParameter] string $merchantApiKey): string
    {
        return base64_encode(hash_hmac('sha256', strtoupper($method) . '+' . $path . '+' . $json, $merchantApiKey, true));
    }

    /**
     * JSON encoding used by the PayPo reference implementation.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $paths Candidate URL paths of the notification endpoint, e.g. with and without the application base path.
     *
     * @throws InvalidNotification When the header is missing or matches none of the candidates.
     */
    public static function verify(string $rawBody, ?string $header, array $paths, #[\SensitiveParameter] string $merchantApiKey, string $method = 'POST'): void
    {
        $header = $header !== null ? trim($header) : '';

        if ($header === '') {
            throw InvalidNotification::missingSignature();
        }

        $bodies = [$rawBody];
        $decoded = json_decode($rawBody, true);

        if (is_array($decoded)) {
            $bodies[] = self::canonicalJson($decoded);
        }

        foreach (array_unique($paths) as $path) {
            foreach (array_unique($bodies) as $body) {
                if (hash_equals(self::compute($method, $path, $body, $merchantApiKey), $header)) {
                    return;
                }
            }
        }

        throw InvalidNotification::invalidSignature();
    }
}
