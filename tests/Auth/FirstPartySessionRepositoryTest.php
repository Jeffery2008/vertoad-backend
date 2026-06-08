<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\FirstPartySessionRepository;

final class FirstPartySessionRepositoryTest extends TestCase
{
    public function testCreateFindAndRevokeSessionByTokenHash(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('users', [
            'id' => 7,
            'email' => 'owner@example.com',
            'status' => 'active',
        ]);

        $repository = new FirstPartySessionRepository($connection);
        $tokenHash = hash('sha256', 'plain-session-token');
        $repository->create(7, $tokenHash, new DateTimeImmutable('2026-06-07 12:00:00'));

        $user = $repository->findActiveUserByTokenHash($tokenHash, new DateTimeImmutable('2026-06-07 11:00:00'));

        self::assertNotNull($user);
        self::assertSame(7, $user->id);
        self::assertSame('owner@example.com', $user->email);
        self::assertSame('2026-06-07 11:00:00', $connection->fetchOne('SELECT last_seen_at FROM first_party_sessions'));

        self::assertTrue($repository->revoke($tokenHash, new DateTimeImmutable('2026-06-07 11:05:00')));
        self::assertNull($repository->findActiveUserByTokenHash($tokenHash, new DateTimeImmutable('2026-06-07 11:06:00')));
    }

    public function testExpiredOrInactiveUserSessionsAreRejected(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('users', [
            'id' => 7,
            'email' => 'owner@example.com',
            'status' => 'disabled',
        ]);

        $repository = new FirstPartySessionRepository($connection);
        $tokenHash = hash('sha256', 'plain-session-token');
        $repository->create(7, $tokenHash, new DateTimeImmutable('2026-06-07 10:00:00'));

        self::assertNull($repository->findActiveUserByTokenHash($tokenHash, new DateTimeImmutable('2026-06-07 09:59:59')));
        self::assertNull($repository->findActiveUserByTokenHash($tokenHash, new DateTimeImmutable('2026-06-07 10:00:01')));
    }

    public function testMalformedSessionTokenHashesAreRejected(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $repository = new FirstPartySessionRepository($connection);

        self::assertNull($repository->findActiveUserByTokenHash('not-a-sha256', new DateTimeImmutable('2026-06-07 11:00:00')));
        self::assertFalse($repository->revoke('not-a-sha256', new DateTimeImmutable('2026-06-07 11:00:00')));
    }

    public function testActiveSessionWithoutRoleTablesAuthenticatesNonSuperAdmin(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL,
                status TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE first_party_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_seen_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->insert('users', [
            'id' => 7,
            'email' => 'owner@example.com',
            'status' => 'active',
        ]);

        $repository = new FirstPartySessionRepository($connection);
        $tokenHash = hash('sha256', 'plain-session-token');
        $repository->create(7, $tokenHash, new DateTimeImmutable('2026-06-07 12:00:00'));

        $user = $repository->findActiveUserByTokenHash($tokenHash, new DateTimeImmutable('2026-06-07 11:00:00'));

        self::assertNotNull($user);
        self::assertFalse($user->isSuperAdmin);
    }

    private function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL,
                status TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NULL,
                slug TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                organization_id INTEGER NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE first_party_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_seen_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }
}
