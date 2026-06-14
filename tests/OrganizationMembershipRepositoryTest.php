<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Repository\OrganizationMembershipRepository;

final class OrganizationMembershipRepositoryTest extends TestCase
{
    public function testListForOrganizationReturnsMembersWithRolesAndPermissions(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $members = (new OrganizationMembershipRepository($connection))->listForOrganization(10);

        self::assertCount(2, $members);
        self::assertSame([
            'member_id' => 1,
            'organization_id' => 10,
            'user_id' => 20,
            'email' => 'owner@example.com',
            'display_name' => 'Owner User',
            'status' => 'active',
            'title' => 'Owner',
            'roles' => ['advertiser-owner'],
            'permissions' => [Permission::OrganizationMembersRead, Permission::OrganizationMembersManage],
        ], $members[0]);
        self::assertSame([
            'member_id' => 2,
            'organization_id' => 10,
            'user_id' => 21,
            'email' => 'viewer@example.com',
            'display_name' => 'viewer@example.com',
            'status' => 'invited',
            'title' => null,
            'roles' => ['viewer'],
            'permissions' => [Permission::OrganizationMembersRead],
        ], $members[1]);
    }

    public function testListForOrganizationReturnsEmptyListForInvalidOrMissingOrganization(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $repository = new OrganizationMembershipRepository($connection);

        self::assertSame([], $repository->listForOrganization(0));
        self::assertSame([], $repository->listForOrganization(404));
    }

    public function testInviteMemberCreatesInvitedUserMembershipAndManagedRole(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $member = (new OrganizationMembershipRepository($connection))->inviteMember(
            10,
            '  New.Member@Example.COM  ',
            'campaign_manager',
        );

        self::assertSame('new.member@example.com', $member['email']);
        self::assertSame('New.Member', $member['display_name']);
        self::assertSame('invited', $member['status']);
        self::assertSame(['campaign_manager'], $member['roles']);
        self::assertContains(Permission::OrganizationMembersRead, $member['permissions']);
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM users WHERE email = 'new.member@example.com' AND status = 'invited'",
        ));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND ur.organization_id = 10 AND r.slug = 'campaign_manager'",
            [$member['user_id']],
        ));
    }

    public function testInviteMemberRejectsExistingOrganizationMembership(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Organization member already exists.');

        (new OrganizationMembershipRepository($connection))->inviteMember(10, 'owner@example.com', 'viewer');
    }

    public function testInviteMemberRejectsMissingOrganizationWithoutCreatingSideEffects(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        try {
            (new OrganizationMembershipRepository($connection))->inviteMember(404, 'new@example.com', 'viewer');
            self::fail('Expected missing organizations to be rejected before membership creation.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Organization was not found.', $exception->getMessage());
        }

        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'new@example.com'"));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM organization_members WHERE organization_id = 404'));
    }

    public function testMemberMutationsRejectInvalidIdentifiersAndRoles(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $repository = new OrganizationMembershipRepository($connection);

        $this->assertInvalidArgument(
            static fn () => $repository->inviteMember(0, 'new@example.com', 'viewer'),
            'organization_id must be a positive integer.',
        );
        $this->assertInvalidArgument(
            static fn () => $repository->updateMemberRole(10, 0, 'viewer'),
            'member_id must be a positive integer.',
        );
        $this->assertInvalidArgument(
            static fn () => $repository->removeMember(10, 0),
            'member_id must be a positive integer.',
        );
        $this->assertInvalidArgument(
            static fn () => $repository->inviteMember(10, 'new@example.com', 'custom'),
            'role_id must be one of owner, admin, campaign_manager, publisher_manager, finance, viewer.',
        );
        $this->assertInvalidArgument(
            static fn () => $repository->updateMemberRole(10, 2, 'custom'),
            'role_id must be one of owner, admin, campaign_manager, publisher_manager, finance, viewer.',
        );
    }

    public function testUpdateMemberRoleReplacesScopedRoleGrants(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $member = (new OrganizationMembershipRepository($connection))->updateMemberRole(10, 2, 'finance');

        self::assertNotNull($member);
        self::assertSame(['finance'], $member['roles']);
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = 21 AND ur.organization_id = 10 AND r.slug = 'finance'",
        ));
        self::assertSame(0, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = 21 AND ur.organization_id = 10 AND r.slug = 'viewer'",
        ));
    }

    public function testUpdateMemberRoleReturnsNullWhenStoredMembershipCannotBeReloaded(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);
        $connection->insert('organization_members', [
            'id' => 4,
            'organization_id' => 10,
            'user_id' => 404,
            'status' => 'active',
            'title' => null,
        ]);

        $member = (new OrganizationMembershipRepository($connection))->updateMemberRole(10, 4, 'viewer');

        self::assertNull($member);
        self::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM user_roles WHERE user_id = 404 AND organization_id = 10',
        ));
    }

    public function testUpdateAndRemoveReturnNullOrFalseForMissingMembers(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $repository = new OrganizationMembershipRepository($connection);

        self::assertNull($repository->updateMemberRole(10, 404, 'viewer'));
        self::assertFalse($repository->removeMember(10, 404));
    }

    public function testRemoveMemberDeletesMembershipAndScopedRoles(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $this->seedListFixture($connection);

        $removed = (new OrganizationMembershipRepository($connection))->removeMember(10, 2);

        self::assertTrue($removed);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM organization_members WHERE id = 2'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM user_roles WHERE user_id = 21 AND organization_id = 10'));
    }

    public function testFindActiveMembershipReturnsScopedRolesAndPermissions(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);

        $connection->insert('organization_members', [
            'id' => 1,
            'organization_id' => 10,
            'user_id' => 20,
            'status' => 'active',
            'title' => 'Owner',
        ]);
        $connection->insert('roles', [
            'id' => 30,
            'organization_id' => 10,
            'slug' => 'publisher-manager',
            'name' => 'Publisher Manager',
        ]);
        $connection->insert('permissions', [
            'id' => 40,
            'slug' => Permission::PublisherSitesManage,
            'description' => 'Manage publisher sites',
        ]);
        $connection->insert('role_permissions', ['role_id' => 30, 'permission_id' => 40]);
        $connection->insert('user_roles', ['user_id' => 20, 'role_id' => 30, 'organization_id' => 10]);

        $membership = (new OrganizationMembershipRepository($connection))->findActiveMembership(20, 10);

        self::assertNotNull($membership);
        self::assertSame(10, $membership->organizationId);
        self::assertSame(20, $membership->userId);
        self::assertSame(['publisher-manager'], $membership->roleSlugs);
        self::assertSame([Permission::PublisherSitesManage], $membership->permissions);
    }

    public function testFindActiveMembershipIgnoresInactiveAndOtherTenantGrants(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);

        $connection->insert('organization_members', [
            'id' => 1,
            'organization_id' => 10,
            'user_id' => 20,
            'status' => 'disabled',
            'title' => null,
        ]);
        $connection->insert('roles', [
            'id' => 30,
            'organization_id' => 11,
            'slug' => 'ledger-reader',
            'name' => 'Ledger Reader',
        ]);
        $connection->insert('permissions', [
            'id' => 40,
            'slug' => Permission::LedgerRead,
            'description' => 'Read ledger',
        ]);
        $connection->insert('role_permissions', ['role_id' => 30, 'permission_id' => 40]);
        $connection->insert('user_roles', ['user_id' => 20, 'role_id' => 30, 'organization_id' => 11]);

        $repository = new OrganizationMembershipRepository($connection);

        self::assertNull($repository->findActiveMembership(20, 10));
    }

    private function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL DEFAULT "unused",
                display_name TEXT NULL,
                status TEXT NOT NULL DEFAULT "active",
                email_verified_at TEXT NULL,
                last_login_at TEXT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE organizations (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE organization_members (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                title TEXT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NULL,
                slug TEXT NOT NULL,
                name TEXT NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL,
                description TEXT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE role_permissions (
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE user_roles (
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                organization_id INTEGER NULL
            )'
        );
    }

    private function seedListFixture(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->insert('organizations', ['id' => 10, 'name' => 'Acme Ads', 'slug' => 'acme-ads']);
        $connection->insert('organizations', ['id' => 11, 'name' => 'Other Org', 'slug' => 'other-org']);
        $connection->insert('users', ['id' => 20, 'email' => 'owner@example.com', 'display_name' => 'Owner User']);
        $connection->insert('users', ['id' => 21, 'email' => 'viewer@example.com', 'display_name' => null]);
        $connection->insert('users', ['id' => 22, 'email' => 'other@example.com', 'display_name' => 'Other User']);
        $connection->insert('organization_members', ['id' => 1, 'organization_id' => 10, 'user_id' => 20, 'status' => 'active', 'title' => 'Owner']);
        $connection->insert('organization_members', ['id' => 2, 'organization_id' => 10, 'user_id' => 21, 'status' => 'invited', 'title' => null]);
        $connection->insert('organization_members', ['id' => 3, 'organization_id' => 11, 'user_id' => 22, 'status' => 'active', 'title' => null]);
        $connection->insert('roles', ['id' => 30, 'organization_id' => 10, 'slug' => 'advertiser-owner', 'name' => 'Owner']);
        $connection->insert('roles', ['id' => 31, 'organization_id' => 10, 'slug' => 'viewer', 'name' => 'Viewer']);
        $connection->insert('roles', ['id' => 32, 'organization_id' => 11, 'slug' => 'other', 'name' => 'Other']);
        $connection->insert('permissions', ['id' => 40, 'slug' => Permission::OrganizationMembersRead, 'description' => 'Read members']);
        $connection->insert('permissions', ['id' => 41, 'slug' => Permission::OrganizationMembersManage, 'description' => 'Manage members']);
        $connection->insert('role_permissions', ['role_id' => 30, 'permission_id' => 40]);
        $connection->insert('role_permissions', ['role_id' => 30, 'permission_id' => 41]);
        $connection->insert('role_permissions', ['role_id' => 31, 'permission_id' => 40]);
        $connection->insert('user_roles', ['user_id' => 20, 'role_id' => 30, 'organization_id' => 10]);
        $connection->insert('user_roles', ['user_id' => 21, 'role_id' => 31, 'organization_id' => 10]);
        $connection->insert('user_roles', ['user_id' => 22, 'role_id' => 32, 'organization_id' => 11]);
    }

    private function assertInvalidArgument(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
