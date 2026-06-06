<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use VertoAD\Domain\Auth\OrganizationMembership;

final class OrganizationMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        $member = $this->connection->createQueryBuilder()
            ->select('organization_id', 'user_id', 'status')
            ->from('organization_members')
            ->where('user_id = :user_id')
            ->andWhere('organization_id = :organization_id')
            ->andWhere("status = 'active'")
            ->setParameter('user_id', $userId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        if ($member === false) {
            return null;
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('r.slug AS role_slug', 'p.slug AS permission_slug')
            ->from('user_roles', 'ur')
            ->innerJoin('ur', 'roles', 'r', 'r.id = ur.role_id')
            ->leftJoin('r', 'role_permissions', 'rp', 'rp.role_id = r.id')
            ->leftJoin('rp', 'permissions', 'p', 'p.id = rp.permission_id')
            ->where('ur.user_id = :user_id')
            ->andWhere('ur.organization_id = :organization_id')
            ->andWhere('r.organization_id = :organization_id')
            ->setParameter('user_id', $userId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        $roleSlugs = [];
        $permissions = [];
        foreach ($rows as $row) {
            $roleSlugs[] = (string) $row['role_slug'];
            if ($row['permission_slug'] !== null) {
                $permissions[] = (string) $row['permission_slug'];
            }
        }

        return new OrganizationMembership(
            organizationId: (int) $member['organization_id'],
            userId: (int) $member['user_id'],
            status: (string) $member['status'],
            roleSlugs: array_values(array_unique($roleSlugs)),
            permissions: array_values(array_unique($permissions)),
        );
    }
}
