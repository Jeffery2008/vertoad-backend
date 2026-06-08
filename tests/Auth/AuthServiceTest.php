<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Service\AuthService;
use VertoAD\Service\PasswordHasher;

final class AuthServiceTest extends TestCase
{
    public function testRegisterNormalizesEmailAndRejectsDuplicates(): void
    {
        $connection = $this->createConnection();
        $service = $this->createService($connection);

        $registered = $service->register('  Owner@Example.COM ', 'correct horse battery staple', 'Owner');

        self::assertSame('owner@example.com', $registered['email']);
        self::assertSame('Owner', $registered['display_name']);
        self::assertNotSame('correct horse battery staple', $connection->fetchOne('SELECT password_hash FROM users'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Email is already registered.');

        $service->register('owner@example.com', 'another secure password', 'Duplicate');
    }

    public function testRegisterRejectsBlankDisplayNameAndInvalidEmail(): void
    {
        $service = $this->createService($this->createConnection());

        try {
            $service->register('owner@example.com', 'correct horse battery staple', '   ');
            self::fail('Blank display name should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Display name is required.', $exception->getMessage());
        }

        try {
            $service->register('not-an-email', 'correct horse battery staple', 'Owner');
            self::fail('Invalid email should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Valid email is required.', $exception->getMessage());
        }
    }

    public function testLoginRejectsInactiveUsersAndWrongPasswords(): void
    {
        $connection = $this->createConnection();
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'id' => 1,
            'email' => 'active@example.com',
            'password_hash' => $hasher->hash('secret password'),
            'display_name' => 'Active',
            'status' => 'active',
        ]);
        $connection->insert('users', [
            'id' => 2,
            'email' => 'inactive@example.com',
            'password_hash' => $hasher->hash('secret password'),
            'display_name' => 'Inactive',
            'status' => 'disabled',
        ]);

        $service = $this->createService($connection);

        try {
            $service->login('active@example.com', 'wrong password');
            self::fail('Wrong password should be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('Invalid credentials.', $exception->getMessage());
        }

        try {
            $service->login('inactive@example.com', 'secret password');
            self::fail('Inactive user should be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('User is not active.', $exception->getMessage());
        }
    }

    public function testLoginReturnsUserAndBearerTokenMetadata(): void
    {
        $connection = $this->createConnection();
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'id' => 1,
            'email' => 'active@example.com',
            'password_hash' => $hasher->hash('secret password'),
            'display_name' => 'Active',
            'status' => 'active',
        ]);
        $service = $this->createService($connection, tokenFactory: static fn (): string => 'fixed-login-token');

        $result = $service->login(' ACTIVE@example.com ', 'secret password', new DateTimeImmutable('2026-06-07 09:00:00'));

        self::assertSame(['id' => 1, 'email' => 'active@example.com', 'display_name' => 'Active'], $result['user']);
        self::assertSame([
            'access_token' => 'fixed-login-token',
            'token_type' => 'Bearer',
            'expires_in' => 900,
        ], $result['token']);
        self::assertSame('2026-06-07 09:00:00', $connection->fetchOne('SELECT last_login_at FROM users WHERE id = 1'));
        self::assertSame(
            hash('sha256', 'fixed-login-token'),
            $connection->fetchOne('SELECT session_token_hash FROM first_party_sessions WHERE user_id = 1'),
        );
    }

    public function testLoginUsesRandomTokenWhenNoFactoryIsProvided(): void
    {
        $connection = $this->createConnection();
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'id' => 1,
            'email' => 'active@example.com',
            'password_hash' => $hasher->hash('secret password'),
            'display_name' => 'Active',
            'status' => 'active',
        ]);

        $result = $this->createService($connection)->login('active@example.com', 'secret password');

        self::assertNotEmpty($result['token']['access_token']);
        self::assertSame(hash('sha256', $result['token']['access_token']), $connection->fetchOne('SELECT session_token_hash FROM first_party_sessions'));
    }

    public function testPasswordResetRequestDoesNotLeakWhetherEmailExists(): void
    {
        $connection = $this->createConnection();
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'id' => 1,
            'email' => 'active@example.com',
            'password_hash' => $hasher->hash('old password'),
            'display_name' => 'Active',
            'status' => 'active',
        ]);
        $service = $this->createService($connection, tokenFactory: static fn (): string => 'reset-token');

        $missing = $service->requestPasswordReset('missing@example.com', new DateTimeImmutable('2026-06-07 10:00:00'));
        $existing = $service->requestPasswordReset('active@example.com', new DateTimeImmutable('2026-06-07 10:00:00'));

        self::assertSame(['accepted' => true, 'reset_token' => null], $missing);
        self::assertSame(['accepted' => true, 'reset_token' => 'reset-token'], $existing);
        self::assertSame(hash('sha256', 'reset-token'), $connection->fetchOne('SELECT token_hash FROM password_reset_tokens'));
    }

    public function testPasswordResetRequestStoresPackedIpAndRejectsInvalidIp(): void
    {
        $connection = $this->createConnection();
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'id' => 1,
            'email' => 'active@example.com',
            'password_hash' => $hasher->hash('old password'),
            'display_name' => 'Active',
            'status' => 'active',
        ]);
        $service = $this->createService($connection, tokenFactory: static fn (): string => 'reset-token');

        $service->requestPasswordReset(
            'active@example.com',
            new DateTimeImmutable('2026-06-07 10:00:00'),
            '127.0.0.1',
            ' Mozilla/5.0 ',
        );

        self::assertSame("\x7f\x00\x00\x01", $connection->fetchOne('SELECT requested_ip FROM password_reset_tokens'));
        self::assertSame('Mozilla/5.0', $connection->fetchOne('SELECT user_agent FROM password_reset_tokens'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password reset request IP address is invalid.');

        $service->requestPasswordReset(
            'active@example.com',
            new DateTimeImmutable('2026-06-07 10:01:00'),
            'not-an-ip',
        );
    }

    public function testPasswordResetConfirmConsumesTokenOnceAndUpdatesPassword(): void
    {
        $connection = $this->createConnection();
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'id' => 1,
            'email' => 'active@example.com',
            'password_hash' => $hasher->hash('old password'),
            'display_name' => 'Active',
            'status' => 'active',
        ]);
        $service = $this->createService($connection, tokenFactory: static fn (): string => 'reset-token');
        $service->requestPasswordReset('active@example.com', new DateTimeImmutable('2026-06-07 10:00:00'));

        $result = $service->confirmPasswordReset(
            'reset-token',
            'new secure password',
            new DateTimeImmutable('2026-06-07 10:10:00'),
        );

        self::assertSame(['password_reset' => true, 'user_id' => 1], $result);
        self::assertTrue($hasher->verify('new secure password', (string) $connection->fetchOne('SELECT password_hash FROM users WHERE id = 1')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Password reset token is invalid or expired.');

        $service->confirmPasswordReset(
            'reset-token',
            'another password',
            new DateTimeImmutable('2026-06-07 10:11:00'),
        );
    }

    private function createService(Connection $connection, ?callable $tokenFactory = null): AuthService
    {
        return new AuthService(
            connection: $connection,
            passwordHasher: new PasswordHasher(),
            resetTokens: new PasswordResetTokenRepository($connection),
            sessions: new FirstPartySessionRepository($connection),
            tokenFactory: $tokenFactory,
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    requested_ip BLOB NULL,
    user_agent TEXT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE first_party_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    last_seen_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );

        return $connection;
    }
}
