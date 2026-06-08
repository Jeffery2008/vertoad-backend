<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use VertoAD\Domain\Auth\PasswordResetToken;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;

final class AuthService
{
    /**
     * @param callable|null $tokenFactory
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $passwordHasher,
        private readonly PasswordResetTokenRepositoryInterface $resetTokens,
        private readonly FirstPartySessionRepositoryInterface $sessions,
        private readonly mixed $tokenFactory = null,
    ) {
    }

    /**
     * @return array{id: int, email: string, display_name: string}
     */
    public function register(string $email, string $password, string $displayName): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new \InvalidArgumentException('Display name is required.');
        }

        try {
            $this->connection->insert(
                'users',
                [
                    'email' => $normalizedEmail,
                    'password_hash' => $this->passwordHasher->hash($password),
                    'display_name' => $displayName,
                    'status' => 'active',
                ],
                [
                    'email' => ParameterType::STRING,
                    'password_hash' => ParameterType::STRING,
                    'display_name' => ParameterType::STRING,
                    'status' => ParameterType::STRING,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('Email is already registered.');
        }

        return [
            'id' => (int) $this->connection->lastInsertId(),
            'email' => $normalizedEmail,
            'display_name' => $displayName,
        ];
    }

    /**
     * @return array{user: array{id: int, email: string, display_name: string}, token: array{access_token: string, token_type: string, expires_in: int}}
     */
    public function login(string $email, string $password, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $row = $this->findUserByEmail($this->normalizeEmail($email));
        if ($row === null || !$this->passwordHasher->verify($password, (string) $row['password_hash'])) {
            throw new RuntimeException('Invalid credentials.');
        }

        if ((string) $row['status'] !== 'active') {
            throw new RuntimeException('User is not active.');
        }

        $this->connection->update(
            'users',
            ['last_login_at' => $this->formatDateTime($now)],
            ['id' => (int) $row['id']],
            ['last_login_at' => ParameterType::STRING, 'id' => ParameterType::INTEGER],
        );

        $plainToken = $this->newToken();
        $this->sessions->create(
            (int) $row['id'],
            hash('sha256', $plainToken),
            $now->add(new DateInterval('PT15M')),
        );

        return [
            'user' => [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
            ],
            'token' => [
                'access_token' => $plainToken,
                'token_type' => 'Bearer',
                'expires_in' => 900,
            ],
        ];
    }

    /**
     * @return array{accepted: true, reset_token: string|null}
     */
    public function requestPasswordReset(
        string $email,
        ?DateTimeImmutable $now = null,
        ?string $requestedIp = null,
        ?string $userAgent = null,
    ): array {
        $now ??= new DateTimeImmutable();
        $row = $this->findUserByEmail($this->normalizeEmail($email));
        if ($row === null || (string) $row['status'] !== 'active') {
            return ['accepted' => true, 'reset_token' => null];
        }

        $plainToken = $this->newToken();
        $this->resetTokens->store(new PasswordResetToken(
            id: null,
            userId: (int) $row['id'],
            tokenHash: hash('sha256', $plainToken),
            expiresAt: $now->add(new DateInterval('PT30M')),
            usedAt: null,
            requestedIp: $this->packIpAddress($requestedIp),
            userAgent: $userAgent === null ? null : trim($userAgent),
        ));

        return ['accepted' => true, 'reset_token' => $plainToken];
    }

    /**
     * @return array{password_reset: true, user_id: int}
     */
    public function confirmPasswordReset(string $plainToken, string $newPassword, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $token = $this->resetTokens->consumeUsableToken(hash('sha256', $plainToken), $now);
        if ($token === null) {
            throw new RuntimeException('Password reset token is invalid or expired.');
        }

        $this->connection->update(
            'users',
            ['password_hash' => $this->passwordHasher->hash($newPassword)],
            ['id' => $token->userId],
            ['password_hash' => ParameterType::STRING, 'id' => ParameterType::INTEGER],
        );

        return ['password_reset' => true, 'user_id' => $token->userId];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByEmail(string $email): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'email', 'password_hash', 'display_name', 'status')
            ->from('users')
            ->where('email = :email')
            ->setParameter('email', $email)
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Valid email is required.');
        }

        return $email;
    }

    private function newToken(): string
    {
        if (is_callable($this->tokenFactory)) {
            return (string) ($this->tokenFactory)();
        }

        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function packIpAddress(?string $ipAddress): ?string
    {
        if ($ipAddress === null || trim($ipAddress) === '') {
            return null;
        }

        $packed = inet_pton(trim($ipAddress));
        if ($packed === false) {
            throw new \InvalidArgumentException('Password reset request IP address is invalid.');
        }

        return $packed;
    }
}
