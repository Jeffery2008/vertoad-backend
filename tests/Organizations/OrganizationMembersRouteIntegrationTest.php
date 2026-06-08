<?php

declare(strict_types=1);

namespace VertoAD\Tests\Organizations;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Http\Action\Organizations\ListOrganizationMembersAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class OrganizationMembersRouteIntegrationTest extends TestCase
{
    public function testActionRejectsInvalidOrganizationPathParameter(): void
    {
        $action = new ListOrganizationMembersAction($this->emptyMembershipRepository());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/organizations/not-a-number/members');

        $response = $action($request, new Response(), ['organization_id' => 'not-a-number']);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_organization', $decoded['code']);
    }

    public function testActionRequiresAuthenticatedContext(): void
    {
        $action = new ListOrganizationMembersAction($this->emptyMembershipRepository());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/organizations/99/members');

        $response = $action($request, new Response(), ['organization_id' => '99']);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('authentication_required', $decoded['code']);
    }

    public function testListsOrganizationMembersThroughAuthenticatedPermissionedRoute(): void
    {
        $app = $this->createApp($this->createConnection());

        $response = $this->handleJson($app, '/api/v1/organizations/99/members');

        self::assertSame(200, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('owner@example.com', $response['body']['data']['members'][0]['email']);
        self::assertSame('Owner User', $response['body']['data']['members'][0]['display_name']);
        self::assertSame(['advertiser-owner'], $response['body']['data']['members'][0]['roles']);
        self::assertSame([Permission::OrganizationMembersRead], $response['body']['data']['members'][0]['permissions']);
        self::assertSame('viewer@example.com', $response['body']['data']['members'][1]['email']);
        self::assertCount(2, $response['body']['data']['members']);
    }

    public function testRequiresMembershipPermissionForOrganizationMembersRoute(): void
    {
        $app = $this->createApp($this->createConnection(includePermission: false));

        $response = $this->handleJson($app, '/api/v1/organizations/99/members');

        self::assertSame(403, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('permission_required', $response['body']['error']['code']);
        self::assertSame(Permission::OrganizationMembersRead, $response['body']['error']['required_permission']);
    }

    /** @return array{status:int, body:array<string, mixed>} */
    private function handleJson(\Slim\App $app, string $uri): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withHeader('Authorization', 'Bearer fixed-token');
        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    private function emptyMembershipRepository(): OrganizationMembershipRepositoryInterface
    {
        return new class implements OrganizationMembershipRepositoryInterface {
            public function findActiveMembership(int $userId, int $organizationId): ?\VertoAD\Domain\Auth\OrganizationMembership
            {
                return null;
            }

            public function listForOrganization(int $organizationId): array
            {
                return [];
            }
        };
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new FirstPartySessionRepository($connection),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new OrganizationMembershipRepository($connection),
            TenantAccessService::class => static fn (
                OrganizationMembershipRepositoryInterface $memberships,
            ): TenantAccessService => new TenantAccessService($memberships, new PermissionMatcher()),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->get('/api/v1/organizations/{organization_id}/members', ListOrganizationMembersAction::class)
            ->add(new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                PermissionRequirement::forOrganization(Permission::OrganizationMembersRead),
            ))
            ->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function createConnection(bool $includePermission = true): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL, display_name TEXT NULL, status TEXT NOT NULL, last_login_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE first_party_sessions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, session_token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, last_seen_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE organization_members (id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, user_id INTEGER NOT NULL, status TEXT NOT NULL, title TEXT NULL)');
        $connection->executeStatement('CREATE TABLE roles (id INTEGER PRIMARY KEY, organization_id INTEGER NULL, slug TEXT NOT NULL, name TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE permissions (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, description TEXT NULL)');
        $connection->executeStatement('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL)');
        $connection->insert('users', ['id' => 1, 'email' => 'owner@example.com', 'password_hash' => 'unused', 'display_name' => 'Owner User', 'status' => 'active', 'last_login_at' => null]);
        $connection->insert('users', ['id' => 2, 'email' => 'viewer@example.com', 'password_hash' => 'unused', 'display_name' => null, 'status' => 'active', 'last_login_at' => null]);
        $connection->insert('first_party_sessions', ['user_id' => 1, 'session_token_hash' => hash('sha256', 'fixed-token'), 'expires_at' => '2099-01-01 00:00:00', 'revoked_at' => null, 'last_seen_at' => null]);
        $connection->insert('organization_members', ['id' => 1, 'organization_id' => 99, 'user_id' => 1, 'status' => 'active', 'title' => 'Owner']);
        $connection->insert('organization_members', ['id' => 2, 'organization_id' => 99, 'user_id' => 2, 'status' => 'active', 'title' => null]);
        $connection->insert('roles', ['id' => 1, 'organization_id' => 99, 'slug' => 'advertiser-owner', 'name' => 'Owner']);
        $connection->insert('roles', ['id' => 2, 'organization_id' => 99, 'slug' => 'viewer', 'name' => 'Viewer']);
        $connection->insert('permissions', ['id' => 1, 'slug' => Permission::OrganizationMembersRead, 'description' => 'Read members']);
        if ($includePermission) {
            $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 1]);
        }
        $connection->insert('user_roles', ['user_id' => 1, 'role_id' => 1, 'organization_id' => 99]);
        $connection->insert('user_roles', ['user_id' => 2, 'role_id' => 2, 'organization_id' => 99]);

        return $connection;
    }
}
