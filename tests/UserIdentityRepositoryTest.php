<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Repository\UserIdentityRepository;

final class UserIdentityRepositoryTest extends TestCase
{
    public function testFindAuthenticatedUserMarksGlobalSuperAdminRole(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);

        $connection->insert('users', [
            'id' => 1,
            'email' => 'admin@example.com',
            'status' => 'active',
        ]);
        $connection->insert('roles', [
            'id' => 2,
            'organization_id' => null,
            'slug' => 'super-admin',
        ]);
        $connection->insert('user_roles', [
            'user_id' => 1,
            'role_id' => 2,
            'organization_id' => null,
        ]);

        $user = (new UserIdentityRepository($connection))->findAuthenticatedUser(1);

        self::assertNotNull($user);
        self::assertSame(1, $user->id);
        self::assertSame('admin@example.com', $user->email);
        self::assertTrue($user->isSuperAdmin);
    }

    public function testFindAuthenticatedUserReturnsNullForInactiveUsers(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema($connection);
        $connection->insert('users', [
            'id' => 1,
            'email' => 'disabled@example.com',
            'status' => 'disabled',
        ]);

        self::assertNull((new UserIdentityRepository($connection))->findAuthenticatedUser(1));
    }

    private function createSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL,
                status TEXT NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NULL,
                slug TEXT NOT NULL
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
