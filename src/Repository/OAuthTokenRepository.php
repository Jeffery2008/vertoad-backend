<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;

final readonly class OAuthTokenRepository implements OAuthTokenRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

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
    ): int {
        $this->connection->insert('oauth_authorization_codes', [
            'client_id' => $client->id,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'code_identifier' => $codeHash,
            'redirect_uri' => $redirectUri,
            'scopes_json' => $this->encodeList($scopes),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => $this->format($expiresAt),
            'revoked_at' => null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function consumeAuthorizationCode(
        string $codeHash,
        int $clientId,
        string $redirectUri,
        string $codeChallenge,
        DateTimeImmutable $now,
    ): ?array
    {
        return $this->connection->transactional(function () use (
            $codeHash,
            $clientId,
            $redirectUri,
            $codeChallenge,
            $now,
        ): ?array {
            $formattedNow = $this->format($now);
            $row = $this->connection->createQueryBuilder()
                ->select('ac.*', 'c.client_identifier', 'c.name', 'c.secret_hash', 'c.redirect_uris_json', 'c.grant_types_json', 'c.scopes_json AS client_scopes_json', 'c.is_confidential', 'c.revoked_at AS client_revoked_at', 'c.owner_user_id')
                ->from('oauth_authorization_codes', 'ac')
                ->innerJoin('ac', 'oauth_clients', 'c', 'c.id = ac.client_id')
                ->where('ac.code_identifier = :code_hash')
                ->andWhere('ac.client_id = :client_id')
                ->andWhere('ac.redirect_uri = :redirect_uri')
                ->andWhere('ac.code_challenge = :code_challenge')
                ->andWhere("ac.code_challenge_method = 'S256'")
                ->andWhere('ac.revoked_at IS NULL')
                ->andWhere('ac.expires_at > :now')
                ->andWhere('c.revoked_at IS NULL')
                ->setParameter('code_hash', $codeHash)
                ->setParameter('client_id', $clientId)
                ->setParameter('redirect_uri', $redirectUri)
                ->setParameter('code_challenge', $codeChallenge)
                ->setParameter('now', $formattedNow)
                ->fetchAssociative();

            if ($row === false) {
                return null;
            }

            $affected = $this->connection->executeStatement(
                'UPDATE oauth_authorization_codes SET revoked_at = ? WHERE id = ? AND client_id = ? AND revoked_at IS NULL',
                [$formattedNow, (int) $row['id'], $clientId],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );

            return $affected === 1 ? $this->hydrateGrantRow($row) : null;
        });
    }

    public function revokeAuthorizationCode(string $codeHash, DateTimeImmutable $now): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE oauth_authorization_codes SET revoked_at = ? WHERE code_identifier = ? AND revoked_at IS NULL',
            [$this->format($now), $codeHash],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $affected > 0;
    }

    public function isAuthorizationCodeActive(string $codeHash, DateTimeImmutable $now): bool
    {
        return (bool) $this->connection->createQueryBuilder()
            ->select('1')
            ->from('oauth_authorization_codes')
            ->where('code_identifier = :code_hash')
            ->andWhere('revoked_at IS NULL')
            ->andWhere('expires_at > :now')
            ->setParameter('code_hash', $codeHash)
            ->setParameter('now', $this->format($now))
            ->fetchOne();
    }

    public function createAccessToken(
        OAuthClient $client,
        ?int $userId,
        ?int $organizationId,
        ?int $authorizationCodeId,
        string $accessTokenHash,
        array $scopes,
        DateTimeImmutable $expiresAt,
    ): int {
        $this->connection->insert('oauth_access_tokens', [
            'client_id' => $client->id,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'authorization_code_id' => $authorizationCodeId,
            'access_token_identifier' => $accessTokenHash,
            'scopes_json' => $this->encodeList($scopes),
            'expires_at' => $this->format($expiresAt),
            'revoked_at' => null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function createRefreshToken(
        int $accessTokenId,
        OAuthClient $client,
        ?int $userId,
        string $refreshTokenHash,
        ?int $previousRefreshTokenId,
        DateTimeImmutable $expiresAt,
    ): int {
        $familyIdentifier = $this->refreshTokenFamilyIdentifier($previousRefreshTokenId, $client->id);
        $this->connection->insert('oauth_refresh_tokens', [
            'access_token_id' => $accessTokenId,
            'client_id' => $client->id,
            'user_id' => $userId,
            'refresh_token_identifier' => $refreshTokenHash,
            'family_identifier' => $familyIdentifier,
            'previous_refresh_token_id' => $previousRefreshTokenId,
            'rotated_to_refresh_token_id' => null,
            'expires_at' => $this->format($expiresAt),
            'revoked_at' => null,
            'rotated_at' => null,
            'reuse_detected_at' => null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findUsableRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): ?array
    {
        $this->lockRefreshTokenRotationCandidate($refreshTokenHash, $now);

        $row = $this->connection->createQueryBuilder()
            ->select('rt.*', 'at.organization_id', 'at.scopes_json', 'c.client_identifier', 'c.name', 'c.secret_hash', 'c.redirect_uris_json', 'c.grant_types_json', 'c.scopes_json AS client_scopes_json', 'c.is_confidential', 'c.revoked_at AS client_revoked_at', 'c.owner_user_id', 'c.organization_id AS client_organization_id')
            ->from('oauth_refresh_tokens', 'rt')
            ->innerJoin('rt', 'oauth_access_tokens', 'at', 'at.id = rt.access_token_id')
            ->innerJoin('rt', 'oauth_clients', 'c', 'c.id = rt.client_id')
            ->where('rt.refresh_token_identifier = :token_hash')
            ->andWhere('rt.revoked_at IS NULL')
            ->andWhere('rt.rotated_at IS NULL')
            ->andWhere('rt.expires_at > :now')
            ->andWhere('c.revoked_at IS NULL')
            ->setParameter('token_hash', $refreshTokenHash)
            ->setParameter('now', $this->format($now))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateGrantRow($row);
    }

    public function rotateRefreshToken(int $oldRefreshTokenId, int $newRefreshTokenId, DateTimeImmutable $now): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE oauth_refresh_tokens SET revoked_at = ?, rotated_at = ?, rotated_to_refresh_token_id = ? WHERE id = ? AND revoked_at IS NULL AND rotated_at IS NULL',
            [$this->format($now), $this->format($now), $newRefreshTokenId, $oldRefreshTokenId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return $affected === 1;
    }

    public function markRefreshTokenReuse(string $refreshTokenHash, DateTimeImmutable $now): bool
    {
        return $this->connection->transactional(function () use ($refreshTokenHash, $now): bool {
            $token = $this->connection->createQueryBuilder()
                ->select('id', 'client_id', 'family_identifier')
                ->from('oauth_refresh_tokens')
                ->where('refresh_token_identifier = :token_hash')
                ->setParameter('token_hash', $refreshTokenHash)
                ->fetchAssociative();
            if ($token === false) {
                return false;
            }

            $timestamp = $this->format($now);
            $familyIdentifier = (string) $token['family_identifier'];
            $clientId = (int) $token['client_id'];

            $this->connection->executeStatement(
                'UPDATE oauth_refresh_tokens SET reuse_detected_at = COALESCE(reuse_detected_at, ?) WHERE id = ?',
                [$timestamp, (int) $token['id']],
                [ParameterType::STRING, ParameterType::INTEGER],
            );
            $this->connection->executeStatement(
                'UPDATE oauth_refresh_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE family_identifier = ? AND client_id = ?',
                [$timestamp, $familyIdentifier, $clientId],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );
            $this->connection->executeStatement(
                'UPDATE oauth_access_tokens
                 SET revoked_at = COALESCE(revoked_at, ?)
                 WHERE id IN (
                     SELECT access_token_id
                     FROM oauth_refresh_tokens
                     WHERE family_identifier = ? AND client_id = ?
                 )',
                [$timestamp, $familyIdentifier, $clientId],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );

            return true;
        });
    }

    public function revokeAccessToken(string $accessTokenHash, DateTimeImmutable $now): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE oauth_access_tokens SET revoked_at = ? WHERE access_token_identifier = ? AND revoked_at IS NULL',
            [$this->format($now), $accessTokenHash],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $affected > 0;
    }

    public function revokeRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE oauth_refresh_tokens SET revoked_at = ? WHERE refresh_token_identifier = ? AND revoked_at IS NULL',
            [$this->format($now), $refreshTokenHash],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $affected > 0;
    }

    public function isAccessTokenActive(string $accessTokenHash, DateTimeImmutable $now): bool
    {
        return (bool) $this->connection->createQueryBuilder()
            ->select('1')
            ->from('oauth_access_tokens')
            ->where('access_token_identifier = :token_hash')
            ->andWhere('revoked_at IS NULL')
            ->andWhere('expires_at > :now')
            ->setParameter('token_hash', $accessTokenHash)
            ->setParameter('now', $this->format($now))
            ->fetchOne();
    }

    public function findActiveUserByAccessTokenHash(string $accessTokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        return $this->findActiveAccessTokenContext($accessTokenHash, $now)?->user;
    }

    public function findActiveAccessTokenContext(string $accessTokenHash, DateTimeImmutable $now): ?OAuthAccessTokenContext
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'at.id AS access_token_id',
                'at.client_id',
                'at.user_id',
                'at.organization_id',
                'at.scopes_json',
                'c.client_identifier',
                'c.organization_id AS client_organization_id',
                'u.id AS hydrated_user_id',
                'u.email AS hydrated_user_email',
            )
            ->from('oauth_access_tokens', 'at')
            ->innerJoin('at', 'oauth_clients', 'c', 'c.id = at.client_id')
            ->leftJoin('at', 'users', 'u', 'u.id = at.user_id')
            ->where('at.access_token_identifier = :token_hash')
            ->andWhere('at.revoked_at IS NULL')
            ->andWhere('at.expires_at > :now')
            ->andWhere('c.revoked_at IS NULL')
            ->andWhere("(at.user_id IS NULL OR u.status = 'active')")
            ->setParameter('token_hash', $accessTokenHash)
            ->setParameter('now', $this->format($now))
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $user = $row['hydrated_user_id'] === null
            ? null
            : new AuthenticatedUser((int) $row['hydrated_user_id'], (string) $row['hydrated_user_email'], false);
        $organizationId = $row['organization_id'] === null
            ? ($row['client_organization_id'] === null ? null : (int) $row['client_organization_id'])
            : (int) $row['organization_id'];

        return new OAuthAccessTokenContext(
            accessTokenId: (int) $row['access_token_id'],
            clientId: (int) $row['client_id'],
            clientIdentifier: (string) $row['client_identifier'],
            organizationId: $organizationId,
            user: $user,
            scopes: $this->decodeList((string) ($row['scopes_json'] ?? '[]')),
        );
    }

    public function cleanupExpiredTokens(DateTimeImmutable $now, int $retentionSeconds): array
    {
        if ($retentionSeconds < 0) {
            throw new \InvalidArgumentException('Expired token cleanup retention seconds must be non-negative.');
        }

        $cutoff = $now->sub(new DateInterval('PT' . $retentionSeconds . 'S'));
        $cutoffValue = $this->format($cutoff);
        $refreshDeleted = $this->connection->executeStatement(
            'DELETE FROM oauth_refresh_tokens
             WHERE expires_at <= ?
                OR revoked_at <= ?
                OR rotated_at <= ?
                OR reuse_detected_at <= ?',
            [$cutoffValue, $cutoffValue, $cutoffValue, $cutoffValue],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );
        $accessDeleted = $this->connection->executeStatement(
            'DELETE FROM oauth_access_tokens
             WHERE (expires_at <= ? OR revoked_at <= ?)
               AND NOT EXISTS (
                   SELECT 1 FROM oauth_refresh_tokens WHERE oauth_refresh_tokens.access_token_id = oauth_access_tokens.id
               )',
            [$cutoffValue, $cutoffValue],
            [ParameterType::STRING, ParameterType::STRING],
        );
        $authorizationCodesDeleted = $this->connection->executeStatement(
            'DELETE FROM oauth_authorization_codes WHERE expires_at <= ? OR revoked_at <= ?',
            [$cutoffValue, $cutoffValue],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return [
            'authorization_codes_deleted' => $authorizationCodesDeleted,
            'access_tokens_deleted' => $accessDeleted,
            'refresh_tokens_deleted' => $refreshDeleted,
        ];
    }

    /** @param list<string> $values */
    private function encodeList(array $values): string
    {
        return json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $row */
    private function hydrateGrantRow(array $row): array
    {
        return $row + [
            'client' => new OAuthClient(
                id: (int) ($row['client_id'] ?? $row['id']),
                organizationId: isset($row['client_organization_id']) ? (int) $row['client_organization_id'] : ($row['organization_id'] === null ? null : (int) $row['organization_id']),
                ownerUserId: $row['owner_user_id'] === null ? null : (int) $row['owner_user_id'],
                clientIdentifier: (string) $row['client_identifier'],
                name: (string) $row['name'],
                secretHash: $row['secret_hash'] === null ? null : (string) $row['secret_hash'],
                redirectUris: $this->decodeList((string) $row['redirect_uris_json']),
                grantTypes: $this->decodeList((string) $row['grant_types_json']),
                scopes: $this->decodeList((string) ($row['client_scopes_json'] ?? '[]')),
                isConfidential: (bool) $row['is_confidential'],
                revokedAt: null,
            ),
            'scopes' => $this->decodeList((string) ($row['scopes_json'] ?? '[]')),
        ];
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function refreshTokenFamilyIdentifier(?int $previousRefreshTokenId, ?int $clientId): string
    {
        if ($previousRefreshTokenId === null) {
            return bin2hex(random_bytes(32));
        }

        $familyIdentifier = $this->connection->createQueryBuilder()
            ->select('family_identifier')
            ->from('oauth_refresh_tokens')
            ->where('id = :previous_id')
            ->andWhere('client_id = :client_id')
            ->setParameter('previous_id', $previousRefreshTokenId)
            ->setParameter('client_id', $clientId)
            ->fetchOne();
        if (!is_string($familyIdentifier) || $familyIdentifier === '') {
            throw new RuntimeException('Previous OAuth refresh token family was not found for this client.');
        }

        return $familyIdentifier;
    }

    private function lockRefreshTokenRotationCandidate(string $refreshTokenHash, DateTimeImmutable $now): void
    {
        if (!$this->connection->isTransactionActive()) {
            return;
        }

        // Lock before inserting the successor; concurrent InnoDB FK checks can otherwise deadlock on lock upgrade.
        $this->connection->executeStatement(
            'UPDATE oauth_refresh_tokens
             SET refresh_token_identifier = refresh_token_identifier
             WHERE refresh_token_identifier = ?
               AND revoked_at IS NULL
               AND rotated_at IS NULL
               AND expires_at > ?',
            [$refreshTokenHash, $this->format($now)],
            [ParameterType::STRING, ParameterType::STRING],
        );
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
