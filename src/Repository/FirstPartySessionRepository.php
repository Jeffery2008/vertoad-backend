<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Auth\AuthenticatedUser;

final class FirstPartySessionRepository implements FirstPartySessionRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
        $this->connection->insert(
            'first_party_sessions',
            [
                'user_id' => $userId,
                'session_token_hash' => $tokenHash,
                'expires_at' => $this->formatDateTime($expiresAt),
            ],
            [
                'user_id' => ParameterType::INTEGER,
                'session_token_hash' => ParameterType::STRING,
                'expires_at' => ParameterType::STRING,
            ],
        );
    }

    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('u.id', 'u.email')
            ->from('first_party_sessions', 's')
            ->innerJoin('s', 'users', 'u', 'u.id = s.user_id')
            ->where('s.session_token_hash = :token_hash')
            ->andWhere('s.revoked_at IS NULL')
            ->andWhere('s.expires_at > :now')
            ->andWhere("u.status = 'active'")
            ->setParameter('token_hash', $tokenHash)
            ->setParameter('now', $this->formatDateTime($now))
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $this->connection->executeStatement(
            'UPDATE first_party_sessions SET last_seen_at = ? WHERE session_token_hash = ?',
            [$this->formatDateTime($now), $tokenHash],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return new AuthenticatedUser(
            id: (int) $row['id'],
            email: (string) $row['email'],
            isSuperAdmin: $this->hasGlobalSuperAdminRole((int) $row['id']),
        );
    }

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            return false;
        }

        $affected = $this->connection->executeStatement(
            'UPDATE first_party_sessions SET revoked_at = ? WHERE session_token_hash = ? AND revoked_at IS NULL',
            [$this->formatDateTime($revokedAt), $tokenHash],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $affected === 1;
    }

    private function hasGlobalSuperAdminRole(int $userId): bool
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['roles', 'user_roles'])) {
            return false;
        }

        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('user_roles', 'ur')
            ->innerJoin('ur', 'roles', 'r', 'r.id = ur.role_id')
            ->where('ur.user_id = :user_id')
            ->andWhere('ur.organization_id IS NULL')
            ->andWhere('r.organization_id IS NULL')
            ->andWhere("r.slug = 'super-admin'")
            ->setParameter('user_id', $userId)
            ->fetchOne();

        return (int) $count > 0;
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }
}
