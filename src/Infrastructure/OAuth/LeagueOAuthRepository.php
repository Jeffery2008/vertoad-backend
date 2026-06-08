<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\OAuth;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Service\OAuthClientSecretHasher;

final readonly class LeagueOAuthRepository implements
    ClientRepositoryInterface,
    ScopeRepositoryInterface,
    AccessTokenRepositoryInterface,
    AuthCodeRepositoryInterface,
    RefreshTokenRepositoryInterface
{
    public function __construct(
        private OAuthClientRepositoryInterface $clients,
        private OAuthTokenRepositoryInterface $tokens,
        private OAuthClientSecretHasher $secrets,
    ) {
    }

    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $client = $this->clients->findActiveByIdentifier((string) $clientIdentifier);

        return $client === null ? null : new LeagueOAuthClientEntity($client);
    }

    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $client = $this->clients->findActiveByIdentifier((string) $clientIdentifier);
        if ($client === null || !in_array((string) $grantType, $client->grantTypes, true)) {
            return false;
        }

        if (!$client->isConfidential) {
            return true;
        }

        return is_string($clientSecret)
            && $clientSecret !== ''
            && $client->secretHash !== null
            && $this->secrets->verify($clientSecret, $client->secretHash);
    }

    public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
    {
        $scope = trim((string) $identifier);

        return $scope === '' ? null : new LeagueOAuthScopeEntity($scope);
    }

    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        if (!$clientEntity instanceof LeagueOAuthClientEntity) {
            return [];
        }

        $allowed = $clientEntity->domainClient()->scopes;
        $requested = $scopes === []
            ? $allowed
            : array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes);

        foreach ($requested as $scope) {
            if (!in_array($scope, $allowed, true)) {
                return [];
            }
        }

        return array_map(static fn (string $scope): LeagueOAuthScopeEntity => new LeagueOAuthScopeEntity($scope), array_values(array_unique($requested)));
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new LeagueOAuthAccessTokenEntity();
        $token->setClient($clientEntity);
        $token->setUserIdentifier($userIdentifier);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        if (!$accessTokenEntity->getClient() instanceof LeagueOAuthClientEntity) {
            throw new \RuntimeException('League OAuth access token requires a VertoAD client entity.');
        }

        $client = $accessTokenEntity->getClient()->domainClient();
        $accessTokenId = $this->tokens->createAccessToken(
            $client,
            $accessTokenEntity->getUserIdentifier() === null ? null : (int) $accessTokenEntity->getUserIdentifier(),
            $client->organizationId,
            null,
            hash('sha256', $accessTokenEntity->getIdentifier()),
            $this->scopeIdentifiers($accessTokenEntity->getScopes()),
            $accessTokenEntity->getExpiryDateTime(),
        );
        if ($accessTokenEntity instanceof LeagueOAuthAccessTokenEntity) {
            $accessTokenEntity->setPersistedId($accessTokenId);
        }
    }

    public function revokeAccessToken($tokenId): void
    {
        $this->tokens->revokeAccessToken(hash('sha256', (string) $tokenId), new DateTimeImmutable());
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        return !$this->tokens->isAccessTokenActive(hash('sha256', (string) $tokenId), new DateTimeImmutable());
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new LeagueOAuthAuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        if (!$authCodeEntity->getClient() instanceof LeagueOAuthClientEntity) {
            throw new \RuntimeException('League OAuth authorization code requires a VertoAD client entity.');
        }

        $client = $authCodeEntity->getClient()->domainClient();
        $this->tokens->createAuthorizationCode(
            $client,
            (int) $authCodeEntity->getUserIdentifier(),
            $client->organizationId,
            hash('sha256', $authCodeEntity->getIdentifier()),
            (string) $authCodeEntity->getRedirectUri(),
            $this->scopeIdentifiers($authCodeEntity->getScopes()),
            '',
            'S256',
            $authCodeEntity->getExpiryDateTime(),
        );
    }

    public function revokeAuthCode($codeId): void
    {
        $this->tokens->revokeAuthorizationCode(hash('sha256', (string) $codeId), new DateTimeImmutable());
    }

    public function isAuthCodeRevoked($codeId): bool
    {
        return !$this->tokens->isAuthorizationCodeActive(hash('sha256', (string) $codeId), new DateTimeImmutable());
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new LeagueOAuthRefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $accessToken = $refreshTokenEntity->getAccessToken();
        if (!$accessToken->getClient() instanceof LeagueOAuthClientEntity) {
            throw new \RuntimeException('League OAuth refresh token requires a VertoAD client entity.');
        }
        if (!$accessToken instanceof LeagueOAuthAccessTokenEntity || $accessToken->persistedId() === null) {
            throw new \RuntimeException('League OAuth refresh token requires a persisted access token.');
        }

        $client = $accessToken->getClient()->domainClient();
        $this->tokens->createRefreshToken(
            $accessToken->persistedId(),
            $client,
            $accessToken->getUserIdentifier() === null ? null : (int) $accessToken->getUserIdentifier(),
            hash('sha256', $refreshTokenEntity->getIdentifier()),
            null,
            $refreshTokenEntity->getExpiryDateTime(),
        );
    }

    public function revokeRefreshToken($tokenId): void
    {
        $this->tokens->revokeRefreshToken(hash('sha256', (string) $tokenId), new DateTimeImmutable());
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        return $this->tokens->findUsableRefreshToken(hash('sha256', (string) $tokenId), new DateTimeImmutable()) === null;
    }

    /** @param list<ScopeEntityInterface> $scopes */
    private function scopeIdentifiers(array $scopes): array
    {
        return array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes);
    }
}
