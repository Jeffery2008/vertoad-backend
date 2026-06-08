<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\OAuth;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;

final readonly class LeagueOAuthServerFactory
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private LeagueOAuthRepository $repository,
        private array $settings,
    ) {
    }

    public function authorizationServer(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->repository,
            $this->repository,
            $this->repository,
            new CryptKey($this->requiredSetting('private_key_path'), null, false),
            $this->requiredSetting('encryption_key'),
        );

        $authCodeGrant = new AuthCodeGrant(
            $this->repository,
            $this->repository,
            new DateInterval('PT' . $this->ttl('authorization_code_ttl_seconds', 300) . 'S'),
        );
        $authCodeGrant->setRefreshTokenTTL(new DateInterval('PT' . $this->ttl('refresh_token_ttl_seconds', 2592000) . 'S'));

        $refreshTokenGrant = new RefreshTokenGrant($this->repository);
        $refreshTokenGrant->setRefreshTokenTTL(new DateInterval('PT' . $this->ttl('refresh_token_ttl_seconds', 2592000) . 'S'));

        $accessTokenTtl = new DateInterval('PT' . $this->ttl('access_token_ttl_seconds', 900) . 'S');
        $server->enableGrantType($authCodeGrant, $accessTokenTtl);
        $server->enableGrantType(new ClientCredentialsGrant(), $accessTokenTtl);
        $server->enableGrantType($refreshTokenGrant, $accessTokenTtl);

        return $server;
    }

    public function resourceServer(): ResourceServer
    {
        return new ResourceServer($this->repository, new CryptKey($this->requiredSetting('public_key_path'), null, false));
    }

    private function requiredSetting(string $key): string
    {
        $value = trim((string) ($this->settings[$key] ?? ''));
        if ($value === '') {
            throw new \RuntimeException(sprintf('OAuth setting %s is required for league/oauth2-server.', $key));
        }

        return $value;
    }

    private function ttl(string $key, int $default): int
    {
        return max(1, (int) ($this->settings[$key] ?? $default));
    }
}
