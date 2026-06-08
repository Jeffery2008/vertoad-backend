<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Auth\AuthenticatedUser;
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
    public function consumeAuthorizationCode(string $codeHash, DateTimeImmutable $now): ?array;

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

    /** @return array<string, mixed>|null */
    public function findUsableRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): ?array;

    public function rotateRefreshToken(int $oldRefreshTokenId, int $newRefreshTokenId, DateTimeImmutable $now): void;

    public function markRefreshTokenReuse(string $refreshTokenHash, DateTimeImmutable $now): bool;

    public function revokeAccessToken(string $accessTokenHash, DateTimeImmutable $now): bool;

    public function revokeRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): bool;

    public function findActiveUserByAccessTokenHash(string $accessTokenHash, DateTimeImmutable $now): ?AuthenticatedUser;
}
