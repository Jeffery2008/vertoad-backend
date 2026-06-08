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
                display_name TEXT NULL
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
}
