<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthTokenRepository;

final class OAuthRefreshTokenFamilyRepositoryTest extends TestCase
{
    public function testRotationInheritsAnExplicitStableFamilyIdentifier(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->storeClient($connection, 'vocl_family_client');
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $first = $this->createGeneration($connection, $repository, $client, 'family-a-1', null, $now);
        $second = $this->createGeneration($connection, $repository, $client, 'family-a-2', $first['refresh_id'], $now);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['family_identifier']);
        self::assertSame($first['family_identifier'], $second['family_identifier']);
        self::assertTrue($repository->rotateRefreshToken($first['refresh_id'], $second['refresh_id'], $now));
        self::assertFalse($repository->rotateRefreshToken($first['refresh_id'], $second['refresh_id'], $now));
    }

    public function testKnownReuseRevokesOnlyItsFamilyAndIsIdempotent(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->storeClient($connection, 'vocl_family_client');
        $now = new DateTimeImmutable('2026-06-08 10:00:00');

        $first = $this->createGeneration($connection, $repository, $client, 'family-a-1', null, $now);
        $second = $this->createGeneration($connection, $repository, $client, 'family-a-2', $first['refresh_id'], $now);
        self::assertTrue($repository->rotateRefreshToken(
            $first['refresh_id'],
            $second['refresh_id'],
            $now->modify('+1 second'),
        ));
        $other = $this->createGeneration($connection, $repository, $client, 'family-b-1', null, $now);
        self::assertNotSame($first['family_identifier'], $other['family_identifier']);

        $detectedAt = $now->modify('+2 seconds');
        self::assertTrue($repository->markRefreshTokenReuse($first['refresh_hash'], $detectedAt));
        self::assertSame(
            2,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM oauth_refresh_tokens WHERE family_identifier = ? AND revoked_at IS NOT NULL',
                [$first['family_identifier']],
            ),
        );
        self::assertSame(
            2,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM oauth_access_tokens WHERE id IN (?, ?) AND revoked_at IS NOT NULL',
                [$first['access_id'], $second['access_id']],
            ),
        );
        self::assertSame(
            $detectedAt->format('Y-m-d H:i:s'),
            $connection->fetchOne('SELECT reuse_detected_at FROM oauth_refresh_tokens WHERE id = ?', [$first['refresh_id']]),
        );
        self::assertNull($connection->fetchOne('SELECT revoked_at FROM oauth_refresh_tokens WHERE id = ?', [$other['refresh_id']]));
        self::assertNull($connection->fetchOne('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', [$other['access_id']]));

        self::assertTrue($repository->markRefreshTokenReuse(
            $first['refresh_hash'],
            $now->modify('+3 seconds'),
        ));
        self::assertSame(
            $detectedAt->format('Y-m-d H:i:s'),
            $connection->fetchOne('SELECT reuse_detected_at FROM oauth_refresh_tokens WHERE id = ?', [$first['refresh_id']]),
        );
        self::assertSame(
            $detectedAt->format('Y-m-d H:i:s'),
            $connection->fetchOne('SELECT revoked_at FROM oauth_refresh_tokens WHERE id = ?', [$second['refresh_id']]),
        );
        self::assertSame(
            $detectedAt->format('Y-m-d H:i:s'),
            $connection->fetchOne('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', [$second['access_id']]),
        );
    }

    public function testUnknownTokenDoesNotRevokeAnyFamily(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->storeClient($connection, 'vocl_family_client');
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $generation = $this->createGeneration($connection, $repository, $client, 'known-family', null, $now);

        self::assertFalse($repository->markRefreshTokenReuse(hash('sha256', 'random-invalid-token'), $now));
        self::assertNull($connection->fetchOne('SELECT reuse_detected_at FROM oauth_refresh_tokens WHERE id = ?', [$generation['refresh_id']]));
        self::assertNull($connection->fetchOne('SELECT revoked_at FROM oauth_refresh_tokens WHERE id = ?', [$generation['refresh_id']]));
        self::assertNull($connection->fetchOne('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', [$generation['access_id']]));
    }

    public function testUsableRefreshLookupLocksCandidateOnlyInsideAnActiveTransaction(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->storeClient($connection, 'vocl_locking_client');
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $generation = $this->createGeneration($connection, $repository, $client, 'locking-family', null, $now);
        $connection->executeStatement('CREATE TABLE refresh_lock_observations (refresh_token_id INTEGER NOT NULL)');
        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER observe_refresh_candidate_lock
BEFORE UPDATE OF refresh_token_identifier ON oauth_refresh_tokens
BEGIN
    INSERT INTO refresh_lock_observations (refresh_token_id) VALUES (OLD.id);
END
SQL);

        self::assertNotNull($repository->findUsableRefreshToken($generation['refresh_hash'], $now));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM refresh_lock_observations'));

        $connection->beginTransaction();
        try {
            self::assertNotNull($repository->findUsableRefreshToken($generation['refresh_hash'], $now));
            self::assertNull($repository->findUsableRefreshToken(hash('sha256', 'unknown-refresh'), $now));
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM refresh_lock_observations'));
        self::assertSame(
            $generation['refresh_id'],
            (int) $connection->fetchOne('SELECT refresh_token_id FROM refresh_lock_observations'),
        );
    }

    public function testReuseEvidenceAndFamilyRevocationsRollbackTogether(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->storeClient($connection, 'vocl_family_client');
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $generation = $this->createGeneration($connection, $repository, $client, 'atomic-family', null, $now);
        $connection->executeStatement(<<<'SQL'
CREATE TRIGGER reject_family_access_revocation
BEFORE UPDATE OF revoked_at ON oauth_access_tokens
WHEN NEW.revoked_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'forced access revocation failure');
END
SQL);

        try {
            $repository->markRefreshTokenReuse($generation['refresh_hash'], $now->modify('+1 second'));
            self::fail('Expected family revocation to fail atomically.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('forced access revocation failure', $exception->getMessage());
        }

        self::assertNull($connection->fetchOne('SELECT reuse_detected_at FROM oauth_refresh_tokens WHERE id = ?', [$generation['refresh_id']]));
        self::assertNull($connection->fetchOne('SELECT revoked_at FROM oauth_refresh_tokens WHERE id = ?', [$generation['refresh_id']]));
        self::assertNull($connection->fetchOne('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', [$generation['access_id']]));
    }

    public function testRotationCannotInheritAnotherClientsFamily(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $firstClient = $this->storeClient($connection, 'vocl_first_family_client');
        $otherClient = $this->storeClient($connection, 'vocl_other_family_client');
        $now = new DateTimeImmutable('2026-06-08 10:00:00');
        $first = $this->createGeneration($connection, $repository, $firstClient, 'first-client', null, $now);
        $otherAccessId = $repository->createAccessToken(
            $otherClient,
            7,
            99,
            null,
            hash('sha256', 'other-client-access'),
            ['report.read.own'],
            $now->modify('+15 minutes'),
        );

        try {
            $repository->createRefreshToken(
                $otherAccessId,
                $otherClient,
                7,
                hash('sha256', 'other-client-refresh'),
                $first['refresh_id'],
                $now->modify('+30 days'),
            );
            self::fail('Expected a cross-client refresh-token family to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('family was not found for this client', $exception->getMessage());
        }

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens'));
    }

    /**
     * @return array{access_id:int, access_hash:string, refresh_id:int, refresh_hash:string, family_identifier:string}
     */
    private function createGeneration(
        Connection $connection,
        OAuthTokenRepository $repository,
        OAuthClient $client,
        string $label,
        ?int $previousRefreshTokenId,
        DateTimeImmutable $now,
    ): array {
        $accessHash = hash('sha256', $label . '-access');
        $refreshHash = hash('sha256', $label . '-refresh');
        $accessId = $repository->createAccessToken(
            $client,
            7,
            99,
            null,
            $accessHash,
            ['report.read.own'],
            $now->modify('+15 minutes'),
        );
        $refreshId = $repository->createRefreshToken(
            $accessId,
            $client,
            7,
            $refreshHash,
            $previousRefreshTokenId,
            $now->modify('+30 days'),
        );

        return [
            'access_id' => $accessId,
            'access_hash' => $accessHash,
            'refresh_id' => $refreshId,
            'refresh_hash' => $refreshHash,
            'family_identifier' => (string) $connection->fetchOne(
                'SELECT family_identifier FROM oauth_refresh_tokens WHERE id = ?',
                [$refreshId],
            ),
        ];
    }

    private function storeClient(Connection $connection, string $identifier): OAuthClient
    {
        return (new OAuthClientRepository($connection))->store(new OAuthClient(
            id: null,
            organizationId: 99,
            ownerUserId: 7,
            clientIdentifier: $identifier,
            name: 'Family Test Client',
            secretHash: null,
            redirectUris: ['https://app.example.com/oauth/callback'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['report.read.own'],
            isConfidential: false,
            revokedAt: null,
        ));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE oauth_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER NULL, owner_user_id INTEGER NULL, client_identifier TEXT NOT NULL UNIQUE, name TEXT NOT NULL, secret_hash TEXT NULL, redirect_uris_json TEXT NOT NULL, grant_types_json TEXT NOT NULL, scopes_json TEXT NULL, is_confidential INTEGER NOT NULL DEFAULT 1, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NULL, organization_id INTEGER NULL, authorization_code_id INTEGER NULL, access_token_identifier TEXT NOT NULL UNIQUE, scopes_json TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, access_token_id INTEGER NOT NULL, client_id INTEGER NOT NULL, user_id INTEGER NULL, refresh_token_identifier TEXT NOT NULL UNIQUE, family_identifier TEXT NOT NULL, previous_refresh_token_id INTEGER NULL, rotated_to_refresh_token_id INTEGER NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, rotated_at TEXT NULL, reuse_detected_at TEXT NULL)');

        return $connection;
    }
}
