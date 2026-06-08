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

    public function listForOrganization(int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $members = $this->connection->createQueryBuilder()
            ->select(
                'om.id AS member_id',
                'om.organization_id',
                'om.user_id',
                'u.email',
                'u.display_name',
                'om.status',
                'om.title',
            )
            ->from('organization_members', 'om')
            ->innerJoin('om', 'users', 'u', 'u.id = om.user_id')
            ->where('om.organization_id = :organization_id')
            ->orderBy('om.id', 'ASC')
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        if ($members === []) {
            return [];
        }

        $grantsByUser = $this->grantsByUserForOrganization($organizationId);

        return array_map(static function (array $member) use ($grantsByUser): array {
            $userId = (int) $member['user_id'];
            $grants = $grantsByUser[$userId] ?? ['roles' => [], 'permissions' => []];
            $displayName = is_string($member['display_name']) && trim($member['display_name']) !== ''
                ? trim($member['display_name'])
                : (string) $member['email'];

            return [
                'member_id' => (int) $member['member_id'],
                'organization_id' => (int) $member['organization_id'],
                'user_id' => $userId,
                'email' => (string) $member['email'],
                'display_name' => $displayName,
                'status' => (string) $member['status'],
                'title' => $member['title'] === null ? null : (string) $member['title'],
                'roles' => $grants['roles'],
                'permissions' => $grants['permissions'],
            ];
        }, $members);
    }

    /** @return array<int, array{roles:list<string>, permissions:list<string>}> */
    private function grantsByUserForOrganization(int $organizationId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('ur.user_id', 'r.slug AS role_slug', 'p.slug AS permission_slug')
            ->from('user_roles', 'ur')
            ->innerJoin('ur', 'roles', 'r', 'r.id = ur.role_id')
            ->leftJoin('r', 'role_permissions', 'rp', 'rp.role_id = r.id')
            ->leftJoin('rp', 'permissions', 'p', 'p.id = rp.permission_id')
            ->where('ur.organization_id = :organization_id')
            ->andWhere('r.organization_id = :organization_id')
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        $grants = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $grants[$userId] ??= ['roles' => [], 'permissions' => []];
            $grants[$userId]['roles'][] = (string) $row['role_slug'];
            if ($row['permission_slug'] !== null) {
                $grants[$userId]['permissions'][] = (string) $row['permission_slug'];
            }
        }

        foreach ($grants as $userId => $userGrants) {
            $grants[$userId] = [
                'roles' => array_values(array_unique($userGrants['roles'])),
                'permissions' => array_values(array_unique($userGrants['permissions'])),
            ];
        }

        return $grants;
    }
}
