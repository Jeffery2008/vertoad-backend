<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Service\Cron\ExpiredTokenCleanupJob;

final class ExpiredTokenCleanupJobTest extends TestCase
{
    public function testRepositoryDeletesExpiredOauthTokensAfterRetentionWithoutDeletingActiveRefreshParents(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->client();
        $now = new DateTimeImmutable('2026-06-09 12:00:00');
        $expiredOld = new DateTimeImmutable('2026-06-01 12:00:00');
        $expiredRecent = new DateTimeImmutable('2026-06-09 11:30:00');
        $active = new DateTimeImmutable('2026-06-10 12:00:00');

        $repository->createAuthorizationCode($client, 7, 5, 'old-code', 'https://app.example/cb', ['ads.manage'], 'challenge', 'S256', $expiredOld);
        $repository->createAuthorizationCode($client, 7, 5, 'recent-code', 'https://app.example/cb', ['ads.manage'], 'challenge', 'S256', $expiredRecent);
        $repository->createAuthorizationCode($client, 7, 5, 'active-code', 'https://app.example/cb', ['ads.manage'], 'challenge', 'S256', $active);
        $orphanAccess = $repository->createAccessToken($client, 7, 5, null, 'old-access-orphan', ['ads.manage'], $expiredOld);
        $referencedAccess = $repository->createAccessToken($client, 7, 5, null, 'old-access-referenced', ['ads.manage'], $expiredOld);
        $repository->createRefreshToken($referencedAccess, $client, 7, 'active-refresh-keeps-parent', null, $active);
        $expiredAccess = $repository->createAccessToken($client, 7, 5, null, 'old-access-with-expired-refresh', ['ads.manage'], $expiredOld);
        $repository->createRefreshToken($expiredAccess, $client, 7, 'old-refresh', null, $expiredOld);
        $repository->createRefreshToken($orphanAccess, $client, 7, 'recent-refresh', null, $expiredRecent);

        $metrics = $repository->cleanupExpiredTokens($now, 3600);

        self::assertSame(1, $metrics['authorization_codes_deleted'] ?? null);
        self::assertSame(1, $metrics['access_tokens_deleted'] ?? null);
        self::assertSame(1, $metrics['refresh_tokens_deleted'] ?? null);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_authorization_codes'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens'));
        self::assertSame($referencedAccess, (int) $connection->fetchOne("SELECT access_token_id FROM oauth_refresh_tokens WHERE refresh_token_identifier = 'active-refresh-keeps-parent'"));
    }

    public function testCronJobReportsCleanupMetrics(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthTokenRepository($connection);
        $client = $this->client();
        $repository->createAuthorizationCode(
            $client,
            7,
            5,
            'old-code',
            'https://app.example/cb',
            ['ads.manage'],
            'challenge',
            'S256',
            new DateTimeImmutable('2026-06-01 12:00:00'),
        );
        $job = new ExpiredTokenCleanupJob(
            $repository,
            3600,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-09 12:00:00'),
        );

        $result = $job->run();

        self::assertSame('expired-token-cleanup', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(1, $result->metrics['authorization_codes_deleted'] ?? null);
        self::assertSame(0, $result->metrics['access_tokens_deleted'] ?? null);
        self::assertSame(0, $result->metrics['refresh_tokens_deleted'] ?? null);
        self::assertSame('Expired OAuth authorization codes, access tokens, and refresh tokens were cleaned.', $result->message);
    }

    public function testCronJobCanUseDefaultUtcClock(): void
    {
        $job = new ExpiredTokenCleanupJob(new OAuthTokenRepository($this->createConnection()), 3600);

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame(0, $result->metrics['authorization_codes_deleted'] ?? null);
        self::assertSame(0, $result->metrics['access_tokens_deleted'] ?? null);
        self::assertSame(0, $result->metrics['refresh_tokens_deleted'] ?? null);
    }

    public function testCronJobRejectsInvalidRetentionWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expired token cleanup retention seconds must be non-negative.');

        new ExpiredTokenCleanupJob(new OAuthTokenRepository($this->createConnection()), -1);
    }

    public function testRepositoryRejectsInvalidRetentionWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expired token cleanup retention seconds must be non-negative.');

        (new OAuthTokenRepository($this->createConnection()))->cleanupExpiredTokens(new DateTimeImmutable('2026-06-09 12:00:00'), -1);
    }

    private function client(): OAuthClient
    {
        return new OAuthClient(
            id: 1,
            organizationId: 5,
            ownerUserId: 7,
            clientIdentifier: 'client_1',
            name: 'Client',
            secretHash: null,
            redirectUris: ['https://app.example/cb'],
            grantTypes: ['authorization_code', 'refresh_token'],
            scopes: ['ads.manage'],
            isConfidential: false,
            revokedAt: null,
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE oauth_authorization_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NOT NULL, organization_id INTEGER NULL, code_identifier TEXT NOT NULL UNIQUE, redirect_uri TEXT NOT NULL, scopes_json TEXT NULL, code_challenge TEXT NULL, code_challenge_method TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, user_id INTEGER NULL, organization_id INTEGER NULL, authorization_code_id INTEGER NULL, access_token_identifier TEXT NOT NULL UNIQUE, scopes_json TEXT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE oauth_refresh_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, access_token_id INTEGER NOT NULL, client_id INTEGER NOT NULL, user_id INTEGER NULL, refresh_token_identifier TEXT NOT NULL UNIQUE, family_identifier TEXT NOT NULL, previous_refresh_token_id INTEGER NULL, rotated_to_refresh_token_id INTEGER NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, rotated_at TEXT NULL, reuse_detected_at TEXT NULL)');

        return $connection;
    }
}
