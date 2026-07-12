<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Auth\OrganizationMembership;

final class OrganizationMembershipRepository implements OrganizationMembershipRepositoryInterface, OrganizationMemberManagementRepositoryInterface
{
    private const array MANAGED_ROLE_SLUGS = [
        'owner',
        'admin',
        'campaign_manager',
        'publisher_manager',
        'finance',
        'viewer',
    ];

    private const array MANAGED_ROLE_NAMES = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'campaign_manager' => 'Campaign Manager',
        'publisher_manager' => 'Publisher Manager',
        'finance' => 'Finance',
        'viewer' => 'Viewer',
    ];

    private const array MANAGED_ROLE_PERMISSIONS = [
        'owner' => [
            'organizations.members.read',
            'organizations.members.manage',
            'billing.ledger.read.own',
            'billing.recharge_key.redeem.own',
            'billing.withdrawal.request.own',
            'billing.withdrawal.read.own',
            'billing.withdrawal.revoke.own',
            'billing.withdrawal.resubmit.own',
            'campaign.read.own',
            'campaign.write.own',
            'campaign.status.update.own',
            'creative.read.own',
            'creative.write.own',
            'creative.template.read.own',
            'creative.template.write.own',
            'creative.design.read.own',
            'creative.design.write.own',
            'publisher.site.read.own',
            'publisher.site.write.own',
            'publisher.site.verify.own',
            'publisher.slot.read.own',
            'publisher.slot.write.own',
            'sdk.integration.read.own',
            'sdk.oauth_client.read.own',
            'sdk.oauth_client.write.own',
            'sdk.oauth_client.rotate_secret.own',
            'webhook.read.own',
            'webhook.write.own',
            'webhook.secret.rotate.own',
            'webhook.delivery.read.own',
            'attribution.conversion.write.own',
            'support.ticket.read.own',
            'support.ticket.write.own',
            'report.read.own',
            'report.export.own',
            'audit.read.own',
        ],
        'admin' => [
            'organizations.members.read',
            'organizations.members.manage',
            'billing.ledger.read.own',
            'campaign.read.own',
            'campaign.write.own',
            'creative.read.own',
            'creative.write.own',
            'creative.template.read.own',
            'creative.template.write.own',
            'creative.design.read.own',
            'creative.design.write.own',
            'publisher.site.read.own',
            'publisher.site.write.own',
            'publisher.site.verify.own',
            'publisher.slot.read.own',
            'publisher.slot.write.own',
            'sdk.integration.read.own',
            'sdk.oauth_client.read.own',
            'webhook.read.own',
            'webhook.write.own',
            'support.ticket.read.own',
            'support.ticket.write.own',
            'report.read.own',
            'report.export.own',
            'audit.read.own',
        ],
        'campaign_manager' => [
            'organizations.members.read',
            'campaign.read.own',
            'campaign.write.own',
            'campaign.status.update.own',
            'creative.read.own',
            'creative.write.own',
            'creative.template.read.own',
            'creative.template.write.own',
            'creative.design.read.own',
            'creative.design.write.own',
            'sdk.integration.read.own',
            'sdk.oauth_client.read.own',
            'webhook.read.own',
            'webhook.write.own',
            'attribution.conversion.write.own',
            'support.ticket.read.own',
            'support.ticket.write.own',
            'report.read.own',
            'report.export.own',
        ],
        'publisher_manager' => [
            'organizations.members.read',
            'publisher.site.read.own',
            'publisher.site.write.own',
            'publisher.site.verify.own',
            'publisher.slot.read.own',
            'publisher.slot.write.own',
            'billing.withdrawal.request.own',
            'billing.withdrawal.read.own',
            'billing.withdrawal.revoke.own',
            'billing.withdrawal.resubmit.own',
            'support.ticket.read.own',
            'support.ticket.write.own',
            'report.read.own',
            'report.export.own',
        ],
        'finance' => [
            'organizations.members.read',
            'billing.ledger.read.own',
            'billing.recharge_key.redeem.own',
            'billing.withdrawal.request.own',
            'billing.withdrawal.read.own',
            'billing.withdrawal.revoke.own',
            'billing.withdrawal.resubmit.own',
            'support.ticket.read.own',
            'support.ticket.write.own',
            'report.read.own',
            'report.export.own',
            'audit.read.own',
        ],
        'viewer' => [
            'organizations.members.read',
            'campaign.read.own',
            'creative.read.own',
            'creative.template.read.own',
            'creative.design.read.own',
            'publisher.site.read.own',
            'publisher.slot.read.own',
            'sdk.integration.read.own',
            'webhook.read.own',
            'support.ticket.read.own',
            'report.read.own',
        ],
    ];

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
            roleSlugs: $this->uniqueSortedSlugs($roleSlugs),
            permissions: $this->uniqueSortedSlugs($permissions),
        );
    }

    public function listActiveOrganizationsForUser(int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('user_id must be a positive integer.');
        }

        $rows = $this->connection->createQueryBuilder()
            ->select(
                'o.id AS organization_id',
                'o.name AS organization_name',
                'o.slug AS organization_slug',
                'r.slug AS role_slug',
                'p.slug AS permission_slug',
            )
            ->from('organization_members', 'om')
            ->innerJoin('om', 'organizations', 'o', 'o.id = om.organization_id')
            ->leftJoin(
                'om',
                'user_roles',
                'ur',
                'ur.user_id = om.user_id AND ur.organization_id = om.organization_id',
            )
            ->leftJoin(
                'ur',
                'roles',
                'r',
                'r.id = ur.role_id AND r.organization_id = om.organization_id',
            )
            ->leftJoin('r', 'role_permissions', 'rp', 'rp.role_id = r.id')
            ->leftJoin('rp', 'permissions', 'p', 'p.id = rp.permission_id')
            ->where('om.user_id = :user_id')
            ->andWhere("om.status = 'active'")
            ->andWhere("o.billing_status = 'active'")
            ->andWhere("TRIM(o.name) <> ''")
            ->andWhere("TRIM(o.slug) <> ''")
            ->orderBy('o.slug', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->addOrderBy('r.slug', 'ASC')
            ->addOrderBy('p.slug', 'ASC')
            ->setParameter('user_id', $userId)
            ->fetchAllAssociative();

        $organizations = [];
        foreach ($rows as $row) {
            $organizationId = (int) $row['organization_id'];
            $organizations[$organizationId] ??= [
                'id' => $organizationId,
                'name' => (string) $row['organization_name'],
                'slug' => (string) $row['organization_slug'],
                'roles' => [],
                'permissions' => [],
            ];

            if ($row['role_slug'] !== null) {
                $organizations[$organizationId]['roles'][] = (string) $row['role_slug'];
            }
            if ($row['permission_slug'] !== null) {
                $organizations[$organizationId]['permissions'][] = (string) $row['permission_slug'];
            }
        }

        foreach ($organizations as $organizationId => $organization) {
            $organizations[$organizationId]['roles'] = $this->uniqueSortedSlugs($organization['roles']);
            $organizations[$organizationId]['permissions'] = $this->uniqueSortedSlugs($organization['permissions']);
        }

        return array_values($organizations);
    }

    /**
     * @param list<string> $slugs
     * @return list<string>
     */
    private function uniqueSortedSlugs(array $slugs): array
    {
        $normalized = [];
        foreach ($slugs as $slug) {
            $slug = trim($slug);
            if ($slug !== '') {
                $normalized[] = $slug;
            }
        }

        $normalized = array_values(array_unique($normalized, SORT_STRING));
        sort($normalized, SORT_STRING);

        return $normalized;
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

    public function inviteMember(int $organizationId, string $email, string $roleId): array
    {
        $organizationId = $this->requirePositiveOrganizationId($organizationId);
        $this->requireExistingOrganization($organizationId);
        $displayName = $this->displayNameFromEmail($email);
        $email = $this->normalizeEmail($email);
        $roleId = $this->normalizeManagedRoleId($roleId);

        return $this->connection->transactional(function () use ($organizationId, $email, $roleId, $displayName): array {
            $userId = $this->findUserIdByEmail($email);
            if ($userId === null) {
                $this->connection->insert('users', [
                    'email' => $email,
                    'password_hash' => 'invited:' . hash('sha256', $email . ':' . bin2hex(random_bytes(16))),
                    'display_name' => $displayName,
                    'status' => 'invited',
                    'email_verified_at' => null,
                    'last_login_at' => null,
                ]);
                $userId = (int) $this->connection->lastInsertId();
            }

            $existingMemberId = $this->connection->createQueryBuilder()
                ->select('id')
                ->from('organization_members')
                ->where('organization_id = :organization_id')
                ->andWhere('user_id = :user_id')
                ->setParameter('organization_id', $organizationId)
                ->setParameter('user_id', $userId)
                ->fetchOne();
            if ($existingMemberId !== false) {
                throw new \InvalidArgumentException('Organization member already exists.');
            }

            $this->connection->insert('organization_members', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'status' => 'invited',
                'title' => null,
            ], [
                'organization_id' => ParameterType::INTEGER,
                'user_id' => ParameterType::INTEGER,
                'status' => ParameterType::STRING,
                'title' => ParameterType::NULL,
            ]);
            $memberId = (int) $this->connection->lastInsertId();

            $this->replaceMemberRole($organizationId, $userId, $roleId);

            return $this->findMember($organizationId, $memberId)
                ?? throw new \RuntimeException('Invited organization member could not be loaded.');
        });
    }

    public function updateMemberRole(int $organizationId, int $memberId, string $roleId): ?array
    {
        $organizationId = $this->requirePositiveOrganizationId($organizationId);
        $memberId = $this->requirePositiveMemberId($memberId);
        $roleId = $this->normalizeManagedRoleId($roleId);

        return $this->connection->transactional(function () use ($organizationId, $memberId, $roleId): ?array {
            $userId = $this->memberUserId($organizationId, $memberId);
            if ($userId === null) {
                return null;
            }

            $this->replaceMemberRole($organizationId, $userId, $roleId);

            return $this->findMember($organizationId, $memberId);
        });
    }

    public function removeMember(int $organizationId, int $memberId): bool
    {
        $organizationId = $this->requirePositiveOrganizationId($organizationId);
        $memberId = $this->requirePositiveMemberId($memberId);

        return $this->connection->transactional(function () use ($organizationId, $memberId): bool {
            $userId = $this->memberUserId($organizationId, $memberId);
            if ($userId === null) {
                return false;
            }

            $this->connection->delete('user_roles', [
                'user_id' => $userId,
                'organization_id' => $organizationId,
            ], [
                'user_id' => ParameterType::INTEGER,
                'organization_id' => ParameterType::INTEGER,
            ]);
            $deleted = $this->connection->delete('organization_members', [
                'id' => $memberId,
                'organization_id' => $organizationId,
            ], [
                'id' => ParameterType::INTEGER,
                'organization_id' => ParameterType::INTEGER,
            ]);

            return $deleted === 1;
        });
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

    /** @return array<string, mixed>|null */
    private function findMember(int $organizationId, int $memberId): ?array
    {
        foreach ($this->listForOrganization($organizationId) as $member) {
            if ($member['member_id'] === $memberId) {
                return $member;
            }
        }

        return null;
    }

    private function replaceMemberRole(int $organizationId, int $userId, string $roleId): void
    {
        $roleDatabaseId = $this->roleDatabaseId($organizationId, $roleId);

        $this->connection->delete('user_roles', [
            'user_id' => $userId,
            'organization_id' => $organizationId,
        ], [
            'user_id' => ParameterType::INTEGER,
            'organization_id' => ParameterType::INTEGER,
        ]);
        $this->connection->insert('user_roles', [
            'user_id' => $userId,
            'role_id' => $roleDatabaseId,
            'organization_id' => $organizationId,
        ], [
            'user_id' => ParameterType::INTEGER,
            'role_id' => ParameterType::INTEGER,
            'organization_id' => ParameterType::INTEGER,
        ]);
    }

    private function roleDatabaseId(int $organizationId, string $roleId): int
    {
        $value = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('roles')
            ->where('organization_id = :organization_id')
            ->andWhere('slug = :slug')
            ->setParameter('organization_id', $organizationId)
            ->setParameter('slug', $roleId)
            ->fetchOne();

        if ($value !== false) {
            return (int) $value;
        }

        return $this->createManagedRole($organizationId, $roleId);
    }

    private function createManagedRole(int $organizationId, string $roleId): int
    {
        $this->connection->insert('roles', [
            'organization_id' => $organizationId,
            'slug' => $roleId,
            'name' => self::MANAGED_ROLE_NAMES[$roleId],
        ], [
            'organization_id' => ParameterType::INTEGER,
            'slug' => ParameterType::STRING,
            'name' => ParameterType::STRING,
        ]);
        $roleDatabaseId = (int) $this->connection->lastInsertId();

        foreach ($this->permissionIdsForRole($roleId) as $permissionId) {
            $this->connection->insert('role_permissions', [
                'role_id' => $roleDatabaseId,
                'permission_id' => $permissionId,
            ], [
                'role_id' => ParameterType::INTEGER,
                'permission_id' => ParameterType::INTEGER,
            ]);
        }

        return $roleDatabaseId;
    }

    /** @return list<int> */
    private function permissionIdsForRole(string $roleId): array
    {
        $permissions = self::MANAGED_ROLE_PERMISSIONS[$roleId];

        $rows = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('permissions')
            ->where('slug IN (:slugs)')
            ->setParameter('slugs', $permissions, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->fetchFirstColumn();

        return array_values(array_map('intval', $rows));
    }

    private function memberUserId(int $organizationId, int $memberId): ?int
    {
        $value = $this->connection->createQueryBuilder()
            ->select('user_id')
            ->from('organization_members')
            ->where('id = :member_id')
            ->andWhere('organization_id = :organization_id')
            ->setParameter('member_id', $memberId)
            ->setParameter('organization_id', $organizationId)
            ->fetchOne();

        return $value === false ? null : (int) $value;
    }

    private function findUserIdByEmail(string $email): ?int
    {
        $value = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('users')
            ->where('email = :email')
            ->setParameter('email', $email)
            ->fetchOne();

        return $value === false ? null : (int) $value;
    }

    private function requirePositiveOrganizationId(int $organizationId): int
    {
        if ($organizationId <= 0) {
            throw new \InvalidArgumentException('organization_id must be a positive integer.');
        }

        return $organizationId;
    }

    private function requireExistingOrganization(int $organizationId): void
    {
        $exists = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('organizations')
            ->where('id = :organization_id')
            ->setParameter('organization_id', $organizationId)
            ->fetchOne();

        if ($exists === false) {
            throw new \InvalidArgumentException('Organization was not found.');
        }
    }

    private function requirePositiveMemberId(int $memberId): int
    {
        if ($memberId <= 0) {
            throw new \InvalidArgumentException('member_id must be a positive integer.');
        }

        return $memberId;
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Valid email is required.');
        }

        return $email;
    }

    private function normalizeManagedRoleId(string $roleId): string
    {
        $roleId = trim($roleId);
        if (!in_array($roleId, self::MANAGED_ROLE_SLUGS, true)) {
            throw new \InvalidArgumentException('role_id must be one of owner, admin, campaign_manager, publisher_manager, finance, viewer.');
        }

        return $roleId;
    }

    private function displayNameFromEmail(string $email): string
    {
        $localPart = trim((string) strstr($email, '@', true));

        return $localPart === '' ? $email : $localPart;
    }
}
