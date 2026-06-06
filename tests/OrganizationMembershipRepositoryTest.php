<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Repository\OrganizationMembershipRepository;

final class OrganizationMembershipRepositoryTest extends TestCase
{
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
}
