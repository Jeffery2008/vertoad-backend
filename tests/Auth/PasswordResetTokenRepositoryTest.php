<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\PasswordResetToken;
use VertoAD\Repository\PasswordResetTokenRepository;

final class PasswordResetTokenRepositoryTest extends TestCase
{
    public function testStorePersistsOnlyTokenHashAndConsumeMarksTokenUsedOnce(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('users', ['id' => 10]);

        $repository = new PasswordResetTokenRepository($connection);
        $tokenHash = hash('sha256', 'reset-token');
        $stored = $repository->store(new PasswordResetToken(
            id: null,
            userId: 10,
            tokenHash: $tokenHash,
            expiresAt: new DateTimeImmutable('2026-06-07 12:00:00'),
            usedAt: null,
            requestedIp: "\x7f\x00\x00\x01",
            userAgent: 'Mozilla/5.0',
        ));

        self::assertNotNull($stored->id);
        self::assertSame($tokenHash, $connection->fetchOne('SELECT token_hash FROM password_reset_tokens'));
        self::assertStringNotContainsString('reset-token', json_encode($connection->fetchAssociative('SELECT * FROM password_reset_tokens'), JSON_THROW_ON_ERROR));

        $consumed = $repository->consumeUsableToken($tokenHash, new DateTimeImmutable('2026-06-07 11:00:00'));

        self::assertNotNull($consumed);
        self::assertSame(10, $consumed->userId);
        self::assertSame('2026-06-07 11:00:00', (string) $connection->fetchOne('SELECT used_at FROM password_reset_tokens'));
        self::assertNull($repository->consumeUsableToken($tokenHash, new DateTimeImmutable('2026-06-07 11:01:00')));
    }

    public function testConsumeRejectsExpiredTokens(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('users', ['id' => 10]);

        $repository = new PasswordResetTokenRepository($connection);
        $tokenHash = hash('sha256', 'expired-token');
        $repository->store(new PasswordResetToken(
            id: null,
            userId: 10,
            tokenHash: $tokenHash,
            expiresAt: new DateTimeImmutable('2026-06-07 10:00:00'),
            usedAt: null,
            requestedIp: null,
            userAgent: null,
        ));

        self::assertNull($repository->consumeUsableToken($tokenHash, new DateTimeImmutable('2026-06-07 10:00:01')));
        self::assertNull($connection->fetchOne('SELECT used_at FROM password_reset_tokens'));
    }

    public function testDomainRejectsInvalidConstructorArguments(): void
    {
        foreach ([
            'Password reset token ID must be positive when provided.' => ['id' => 0, 'userId' => 10, 'hash' => hash('sha256', 'token'), 'agent' => null],
            'Password reset token user ID must be positive.' => ['id' => null, 'userId' => 0, 'hash' => hash('sha256', 'token'), 'agent' => null],
            'Password reset token hash must be a SHA-256 hex digest.' => ['id' => null, 'userId' => 10, 'hash' => 'not-a-hash', 'agent' => null],
            'Password reset token user agent must be 512 characters or fewer.' => ['id' => null, 'userId' => 10, 'hash' => hash('sha256', 'token'), 'agent' => str_repeat('a', 513)],
        ] as $message => $input) {
            try {
                new PasswordResetToken(
                    id: $input['id'],
                    userId: $input['userId'],
                    tokenHash: $input['hash'],
                    expiresAt: new DateTimeImmutable('2026-06-07 12:00:00'),
                    usedAt: null,
                    requestedIp: null,
                    userAgent: $input['agent'],
                );
                self::fail('Expected invalid password reset token input to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testConsumeRejectsMalformedTokenHash(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);

        self::assertNull((new PasswordResetTokenRepository($connection))->consumeUsableToken(
            'not-a-sha256',
            new DateTimeImmutable('2026-06-07 11:00:00'),
        ));
    }

    public function testConsumeReturnsNullWhenConcurrentUpdateAlreadyUsedToken(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('users', ['id' => 10]);
        $repository = new PasswordResetTokenRepository($connection);
        $tokenHash = hash('sha256', 'reset-token');
        $repository->store(new PasswordResetToken(
            id: null,
            userId: 10,
            tokenHash: $tokenHash,
            expiresAt: new DateTimeImmutable('2026-06-07 12:00:00'),
            usedAt: null,
            requestedIp: null,
            userAgent: null,
        ));
        $connection->executeStatement(
            'CREATE TRIGGER ignore_password_reset_update BEFORE UPDATE ON password_reset_tokens BEGIN SELECT RAISE(IGNORE); END'
        );

        self::assertNull($repository->consumeUsableToken(
            $tokenHash,
            new DateTimeImmutable('2026-06-07 11:00:00'),
        ));
    }

    private function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL,
    requested_ip BLOB NULL,
    user_agent TEXT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
    }
}
