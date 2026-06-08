<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Auth\PasswordResetToken;

final class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function store(PasswordResetToken $token): PasswordResetToken
    {
        $this->connection->insert(
            'password_reset_tokens',
            [
                'user_id' => $token->userId,
                'token_hash' => $token->tokenHash,
                'requested_ip' => $token->requestedIp,
                'user_agent' => $token->userAgent,
                'expires_at' => $this->formatDateTime($token->expiresAt),
                'used_at' => $this->formatDateTime($token->usedAt),
            ],
            [
                'user_id' => ParameterType::INTEGER,
                'token_hash' => ParameterType::STRING,
                'requested_ip' => $token->requestedIp === null ? ParameterType::NULL : ParameterType::BINARY,
                'user_agent' => $token->userAgent === null ? ParameterType::NULL : ParameterType::STRING,
                'expires_at' => ParameterType::STRING,
                'used_at' => $token->usedAt === null ? ParameterType::NULL : ParameterType::STRING,
            ],
        );

        $id = (int) $this->connection->lastInsertId();

        return $id > 0 ? $token->withId($id) : $token;
    }

    public function consumeUsableToken(string $tokenHash, DateTimeImmutable $usedAt): ?PasswordResetToken
    {
        $tokenHash = trim($tokenHash);
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            return null;
        }

        return $this->connection->transactional(function () use ($tokenHash, $usedAt): ?PasswordResetToken {
            $row = $this->connection->createQueryBuilder()
                ->select('id', 'user_id', 'token_hash', 'requested_ip', 'user_agent', 'expires_at', 'used_at')
                ->from('password_reset_tokens')
                ->where('token_hash = :token_hash')
                ->andWhere('used_at IS NULL')
                ->andWhere('expires_at > :used_at')
                ->setParameter('token_hash', $tokenHash)
                ->setParameter('used_at', $this->formatDateTime($usedAt))
                ->fetchAssociative();

            if ($row === false) {
                return null;
            }

            $affected = $this->connection->executeStatement(
                'UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL',
                [$this->formatDateTime($usedAt), (int) $row['id']],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

            if ($affected !== 1) {
                return null;
            }

            return $this->hydrate($row)->markUsed($usedAt);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PasswordResetToken
    {
        return new PasswordResetToken(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: $this->parseDateTime((string) $row['expires_at']),
            usedAt: $this->parseNullableDateTime($row['used_at'] === null ? null : (string) $row['used_at']),
            requestedIp: $row['requested_ip'] === null ? null : (string) $row['requested_ip'],
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
        );
    }

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    private function parseDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    private function parseNullableDateTime(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
