<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Auth;

use Beziworld\PayPo\Exceptions\ApiException;
use Beziworld\PayPo\PayPoConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Obtains OAuth2 access tokens (client_credentials, POST /oauth/tokens) and caches them until shortly before expiry.
 *
 * Tokens are JWTs valid for 30 minutes by default; the cache key covers the
 * API host and the client id, so every merchant account has its own token.
 */
class AccessTokenProvider
{
    private const int EXPIRY_MARGIN = 120;

    private const int DEFAULT_LIFETIME = 1800;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly PayPoConfig $config,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get($this->key());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http
                ->createPendingRequest()
                ->asForm()
                ->acceptJson()
                ->timeout($this->config->timeout)
                ->post($this->config->apiUrl() . '/oauth/tokens', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                ]);
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $token = $payload['access_token'] ?? null;

        if ($response->status() === 400 || $response->status() === 401) {
            throw ApiException::authenticationFailed($response->status(), $payload);
        }

        if ($response->failed()) {
            throw ApiException::fromResponse($payload, $response->status());
        }

        if (! is_string($token) || $token === '') {
            throw ApiException::unexpectedResponse('the token response has no access_token');
        }

        $lifetime = is_numeric($payload['expires_in'] ?? null) ? (int) $payload['expires_in'] : self::DEFAULT_LIFETIME;
        $this->cache->put($this->key(), $token, max(30, $lifetime - self::EXPIRY_MARGIN));

        return $token;
    }

    public function forget(): void
    {
        $this->cache->forget($this->key());
    }

    private function key(): string
    {
        return sprintf('%s:token:%s', $this->config->cachePrefix, hash('sha256', $this->config->apiUrl() . '|' . $this->config->clientId));
    }
}
