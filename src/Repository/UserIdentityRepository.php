<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Auth\AuthenticatedUser;

final class UserIdentityRepository implements UserIdentityRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findAuthenticatedUser(int $userId): ?AuthenticatedUser
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'email')
            ->from('users')
            ->where('id = :id')
            ->andWhere("status = 'active'")
            ->setParameter('id', $userId)
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new AuthenticatedUser(
            id: (int) $row['id'],
            email: (string) $row['email'],
            isSuperAdmin: $this->hasGlobalSuperAdminRole($userId),
        );
    }

    private function hasGlobalSuperAdminRole(int $userId): bool
    {
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
}
