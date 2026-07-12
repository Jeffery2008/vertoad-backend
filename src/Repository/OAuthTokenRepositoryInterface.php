<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;

interface OAuthTokenRepositoryInterface
{
    /** @param list<string> $scopes */
    public function createAuthorizationCode(
        OAuthClient $client,
        int $userId,
        ?int $organizationId,
        string $codeHash,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        DateTimeImmutable $expiresAt,
    ): int;

    /** @return array<string, mixed>|null */
    public function consumeAuthorizationCode(
        string $codeHash,
        int $clientId,
        string $redirectUri,
        string $codeChallenge,
        DateTimeImmutable $now,
    ): ?array;

    public function revokeAuthorizationCode(string $codeHash, DateTimeImmutable $now): bool;

    public function isAuthorizationCodeActive(string $codeHash, DateTimeImmutable $now): bool;

    /** @param list<string> $scopes */
    public function createAccessToken(
        OAuthClient $client,
        ?int $userId,
        ?int $organizationId,
        ?int $authorizationCodeId,
        string $accessTokenHash,
        array $scopes,
        DateTimeImmutable $expiresAt,
    ): int;

    public function createRefreshToken(
        int $accessTokenId,
        OAuthClient $client,
        ?int $userId,
        string $refreshTokenHash,
        ?int $previousRefreshTokenId,
        DateTimeImmutable $expiresAt,
    ): int;

    /**
     * Implementations must serialize a usable rotation candidate when called inside
     * an active transaction, before any successor token is inserted.
     *
     * @return array<string, mixed>|null
     */
    public function findUsableRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): ?array;

    public function rotateRefreshToken(int $oldRefreshTokenId, int $newRefreshTokenId, DateTimeImmutable $now): bool;

    /**
     * Marks a known token as reused and atomically revokes its refresh-token family
     * together with every access token issued for that family.
     */
    public function markRefreshTokenReuse(string $refreshTokenHash, DateTimeImmutable $now): bool;

    public function revokeAccessToken(string $accessTokenHash, DateTimeImmutable $now): bool;

    public function revokeRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): bool;

    public function isAccessTokenActive(string $accessTokenHash, DateTimeImmutable $now): bool;

    public function findActiveUserByAccessTokenHash(string $accessTokenHash, DateTimeImmutable $now): ?AuthenticatedUser;

    public function findActiveAccessTokenContext(string $accessTokenHash, DateTimeImmutable $now): ?OAuthAccessTokenContext;

    /**
     * @return array{authorization_codes_deleted:int, access_tokens_deleted:int, refresh_tokens_deleted:int}
     */
    public function cleanupExpiredTokens(DateTimeImmutable $now, int $retentionSeconds): array;
}
