<?php

declare(strict_types=1);

use Beziworld\PayPo\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Decoded JSON fixture from tests/Fixtures.
 *
 * @return array<array-key, mixed>
 */
function paypoFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true, 32, JSON_THROW_ON_ERROR);
}

/**
 * Raw JSON fixture body.
 */
function paypoRawFixture(string $name): string
{
    return trim((string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'));
}

/**
 * Stub of the OAuth token endpoint as documented (expires_in 1800).
 *
 * @return array<string, mixed>
 */
function paypoTokenResponse(string $token = 'access-token-1'): array
{
    return ['token_type' => 'Bearer', 'expires_in' => 1800, 'access_token' => $token];
}
