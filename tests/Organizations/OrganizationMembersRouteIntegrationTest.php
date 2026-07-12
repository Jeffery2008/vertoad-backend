<?php

declare(strict_types=1);

namespace VertoAD\Tests\Organizations;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Http\Action\Organizations\ListOrganizationMembersAction;
use VertoAD\Http\Action\Organizations\ManageOrganizationMembersAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Repository\AuditLogRepository;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMemberManagementRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\AuditLogService;
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

    public function testManageInviteActionRejectsInvalidOrganizationPathParameter(): void
    {
        $response = $this->manageAction()->invite(
            $this->authenticatedRequest('POST', '/api/v1/organizations/not-a-number/members', [
                'email' => 'analyst@example.com',
                'role_id' => 'viewer',
            ]),
            new Response(),
            ['organization_id' => 'not-a-number'],
        );
        $decoded = $this->decodeActionResponse($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_organization', $decoded['code']);
    }

    public function testManageActionsRequireAuthenticatedContext(): void
    {
        $action = $this->manageAction();
        $factory = new ServerRequestFactory();

        $invite = $action->invite(
            $factory->createServerRequest('POST', '/api/v1/organizations/99/members'),
            new Response(),
            ['organization_id' => '99'],
        );
        $update = $action->updateRole(
            $factory->createServerRequest('PATCH', '/api/v1/organizations/99/members/2'),
            new Response(),
            ['organization_id' => '99', 'member_id' => '2'],
        );
        $remove = $action->remove(
            $factory->createServerRequest('DELETE', '/api/v1/organizations/99/members/2'),
            new Response(),
            ['organization_id' => '99', 'member_id' => '2'],
        );

        self::assertSame(401, $invite->getStatusCode());
        self::assertSame('authentication_required', $this->decodeActionResponse($invite)['code']);
        self::assertSame(401, $update->getStatusCode());
        self::assertSame('authentication_required', $this->decodeActionResponse($update)['code']);
        self::assertSame(401, $remove->getStatusCode());
        self::assertSame('authentication_required', $this->decodeActionResponse($remove)['code']);
    }

    public function testManageUpdateAndRemoveActionsRejectInvalidPathParameters(): void
    {
        $action = $this->manageAction();

        $update = $action->updateRole(
            $this->authenticatedRequest('PATCH', '/api/v1/organizations/99/members/not-a-number', [
                'role_id' => 'viewer',
            ]),
            new Response(),
            ['organization_id' => '99', 'member_id' => 'not-a-number'],
        );
        $remove = $action->remove(
            $this->authenticatedRequest('DELETE', '/api/v1/organizations/not-a-number/members/2'),
            new Response(),
            ['organization_id' => 'not-a-number', 'member_id' => '2'],
        );

        self::assertSame(400, $update->getStatusCode());
        self::assertSame('invalid_organization_member', $this->decodeActionResponse($update)['code']);
        self::assertSame(400, $remove->getStatusCode());
        self::assertSame('invalid_organization_member', $this->decodeActionResponse($remove)['code']);
    }

    public function testManageUpdateActionRejectsMalformedAndEmptyRolePayloads(): void
    {
        $action = $this->manageAction();

        $malformed = $action->updateRole(
            $this->authenticatedRequest(
                'PATCH',
                '/api/v1/organizations/99/members/2',
                (object) ['role_id' => 'viewer'],
            ),
            new Response(),
            ['organization_id' => '99', 'member_id' => '2'],
        );
        $emptyRole = $action->updateRole(
            $this->authenticatedRequest('PATCH', '/api/v1/organizations/99/members/2', ['role_id' => ' ']),
            new Response(),
            ['organization_id' => '99', 'member_id' => '2'],
        );

        self::assertSame(422, $malformed->getStatusCode());
        self::assertSame('role_id must be a string.', $this->decodeActionResponse($malformed)['message']);
        self::assertSame(422, $emptyRole->getStatusCode());
        self::assertSame('role_id must be a non-empty string.', $this->decodeActionResponse($emptyRole)['message']);
    }

    public function testListsOrganizationMembersThroughAuthenticatedPermissionedRoute(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: false));

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

    public function testInvitesOrganizationMemberThroughAuthenticatedPermissionedRoute(): void
    {
        $connection = $this->createConnection(includeManagePermission: true);
        $app = $this->createApp($connection);

        $response = $this->handleJson(
            $app,
            '/api/v1/organizations/99/members',
            method: 'POST',
            body: ['email' => 'analyst@example.com', 'role_id' => 'campaign_manager'],
            headers: ['X-Request-Id' => 'req-org-member-invite'],
        );

        self::assertSame(201, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('analyst@example.com', $response['body']['data']['member']['email']);
        self::assertSame('invited', $response['body']['data']['member']['status']);
        self::assertSame(['campaign_manager'], $response['body']['data']['member']['roles']);
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'organization.member.invite' AND subject_type = 'organization_member'",
        ));
        self::assertSame('req-org-member-invite', $connection->fetchOne("SELECT request_id FROM audit_logs WHERE action = 'organization.member.invite'"));
    }

    public function testInvitingMissingOrganizationReturnsNotFound(): void
    {
        $action = $this->manageAction(memberManagement: new class implements OrganizationMemberManagementRepositoryInterface {
            public function inviteMember(int $organizationId, string $email, string $roleId): array
            {
                throw new \InvalidArgumentException('Organization was not found.');
            }

            public function updateMemberRole(int $organizationId, int $memberId, string $roleId): ?array
            {
                return null;
            }

            public function removeMember(int $organizationId, int $memberId): bool
            {
                return false;
            }
        });

        $response = $action->invite(
            $this->authenticatedRequest('POST', '/api/v1/organizations/404/members', [
                'email' => 'analyst@example.com',
                'role_id' => 'viewer',
            ]),
            new Response(),
            ['organization_id' => '404'],
        );
        $decoded = $this->decodeActionResponse($response);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('invalid_organization', $decoded['code']);
    }

    public function testInvitingExistingOrganizationMemberReturnsConflict(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: true));

        $response = $this->handleJson(
            $app,
            '/api/v1/organizations/99/members',
            method: 'POST',
            body: ['email' => 'owner@example.com', 'role_id' => 'viewer'],
        );

        self::assertSame(409, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('organization_member_conflict', $response['body']['error']['code']);
    }

    public function testUpdatesOrganizationMemberRoleThroughAuthenticatedPermissionedRoute(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: true));

        $response = $this->handleJson(
            $app,
            '/api/v1/organizations/99/members/2',
            method: 'PATCH',
            body: ['role_id' => 'finance'],
        );

        self::assertSame(200, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('viewer@example.com', $response['body']['data']['member']['email']);
        self::assertSame(['finance'], $response['body']['data']['member']['roles']);
    }

    public function testRemovesOrganizationMemberThroughAuthenticatedPermissionedRoute(): void
    {
        $connection = $this->createConnection(includeManagePermission: true);
        $app = $this->createApp($connection);

        $response = $this->handleJson($app, '/api/v1/organizations/99/members/2', method: 'DELETE');

        self::assertSame(200, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame(['removed' => true], $response['body']['data']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM organization_members WHERE id = 2'));
    }

    public function testRemovingMissingOrganizationMemberReturnsNotFound(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: true));

        $response = $this->handleJson($app, '/api/v1/organizations/99/members/404', method: 'DELETE');

        self::assertSame(404, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('organization_member_not_found', $response['body']['error']['code']);
    }

    public function testOrganizationMemberMutationsRequireManagePermission(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: false));

        $response = $this->handleJson(
            $app,
            '/api/v1/organizations/99/members',
            method: 'POST',
            body: ['email' => 'analyst@example.com', 'role_id' => 'viewer'],
        );

        self::assertSame(403, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('permission_required', $response['body']['error']['code']);
        self::assertSame(Permission::OrganizationMembersManage, $response['body']['error']['required_permission']);
    }

    public function testRejectsInvalidOrganizationMemberMutationPayloads(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: true));

        $response = $this->handleJson(
            $app,
            '/api/v1/organizations/99/members',
            method: 'POST',
            body: ['email' => 'not-an-email', 'role_id' => 'viewer'],
        );

        self::assertSame(422, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('invalid_organization_member', $response['body']['error']['code']);
    }

    public function testReturnsNotFoundForMissingOrganizationMemberMutationTarget(): void
    {
        $app = $this->createApp($this->createConnection(includeManagePermission: true));

        $response = $this->handleJson(
            $app,
            '/api/v1/organizations/99/members/404',
            method: 'PATCH',
            body: ['role_id' => 'viewer'],
        );

        self::assertSame(404, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('organization_member_not_found', $response['body']['error']['code']);
    }

    /** @return array{status:int, body:array<string, mixed>} */
    private function handleJson(
        \Slim\App $app,
        string $uri,
        string $method = 'GET',
        ?array $body = null,
        array $headers = [],
    ): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'OrganizationRouteTest/1.0')
            ->withHeader('Authorization', 'Bearer fixed-token');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader((string) $name, (string) $value);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    /** @return array<string, mixed> */
    private function decodeActionResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function authenticatedRequest(string $method, string $uri, mixed $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'OrganizationRouteTest/1.0')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(7, 'manager@example.com', false), 99),
            );

        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $request;
    }

    private function emptyMembershipRepository(): OrganizationMembershipRepositoryInterface
    {
        return new class implements OrganizationMembershipRepositoryInterface {
            public function findActiveMembership(int $userId, int $organizationId): ?\VertoAD\Domain\Auth\OrganizationMembership
            {
                return null;
            }

            public function listActiveOrganizationsForUser(int $userId): array
            {
                return [];
            }

            public function listForOrganization(int $organizationId): array
            {
                return [];
            }
        };
    }

    private function manageAction(
        ?OrganizationMembershipRepositoryInterface $memberships = null,
        ?OrganizationMemberManagementRepositoryInterface $memberManagement = null,
    ): ManageOrganizationMembersAction {
        $memberships ??= $this->emptyMembershipRepository();
        $memberManagement ??= new class implements OrganizationMemberManagementRepositoryInterface {
            public function inviteMember(int $organizationId, string $email, string $roleId): array
            {
                return $this->member($organizationId, 1, $email, $roleId);
            }

            public function updateMemberRole(int $organizationId, int $memberId, string $roleId): ?array
            {
                return $this->member($organizationId, $memberId, 'member@example.com', $roleId);
            }

            public function removeMember(int $organizationId, int $memberId): bool
            {
                return true;
            }

            /** @return array<string, mixed> */
            private function member(int $organizationId, int $memberId, string $email, string $roleId): array
            {
                return [
                    'member_id' => $memberId,
                    'organization_id' => $organizationId,
                    'user_id' => 7,
                    'email' => $email,
                    'display_name' => $email,
                    'status' => 'active',
                    'title' => null,
                    'roles' => [$roleId],
                    'permissions' => [Permission::OrganizationMembersRead],
                ];
            }
        };

        $audit = new AuditLogService(new class implements AuditLogRepositoryInterface {
            public function append(AuditLogEntry $entry): void
            {
            }
        });

        return new ManageOrganizationMembersAction($memberships, $memberManagement, $audit, new ClientIpResolver());
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
            OrganizationMemberManagementRepositoryInterface::class => static fn (): OrganizationMemberManagementRepositoryInterface =>
                new OrganizationMembershipRepository($connection),
            AuditLogRepositoryInterface::class => static fn (): AuditLogRepositoryInterface =>
                new AuditLogRepository($connection),
            AuditLogService::class => static fn (AuditLogRepositoryInterface $repository): AuditLogService =>
                new AuditLogService($repository),
            ClientIpResolver::class => static fn (): ClientIpResolver => new ClientIpResolver(),
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
        $app->post('/api/v1/organizations/{organization_id}/members', [ManageOrganizationMembersAction::class, 'invite'])
            ->add(new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                PermissionRequirement::forOrganization(Permission::OrganizationMembersManage),
            ))
            ->add(AuthenticateRequestMiddleware::class);
        $app->patch('/api/v1/organizations/{organization_id}/members/{member_id}', [ManageOrganizationMembersAction::class, 'updateRole'])
            ->add(new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                PermissionRequirement::forOrganization(Permission::OrganizationMembersManage),
            ))
            ->add(AuthenticateRequestMiddleware::class);
        $app->delete('/api/v1/organizations/{organization_id}/members/{member_id}', [ManageOrganizationMembersAction::class, 'remove'])
            ->add(new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                PermissionRequirement::forOrganization(Permission::OrganizationMembersManage),
            ))
            ->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function createConnection(bool $includePermission = true, bool $includeManagePermission = true): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL, display_name TEXT NULL, status TEXT NOT NULL, email_verified_at TEXT NULL, last_login_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE first_party_sessions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, session_token_hash TEXT NOT NULL, expires_at TEXT NOT NULL, revoked_at TEXT NULL, last_seen_at TEXT NULL)');
        $connection->executeStatement('CREATE TABLE organizations (id INTEGER PRIMARY KEY, name TEXT NOT NULL, slug TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE organization_members (id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, user_id INTEGER NOT NULL, status TEXT NOT NULL, title TEXT NULL)');
        $connection->executeStatement('CREATE TABLE roles (id INTEGER PRIMARY KEY, organization_id INTEGER NULL, slug TEXT NOT NULL, name TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE permissions (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, description TEXT NULL)');
        $connection->executeStatement('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL)');
        $connection->executeStatement('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, organization_id INTEGER NULL, actor_user_id INTEGER NULL, action TEXT NOT NULL, subject_type TEXT NOT NULL, subject_id INTEGER NULL, ip_address BLOB NULL, user_agent TEXT NULL, request_id TEXT NULL, metadata_json TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $connection->insert('users', ['id' => 1, 'email' => 'owner@example.com', 'password_hash' => 'unused', 'display_name' => 'Owner User', 'status' => 'active', 'last_login_at' => null]);
        $connection->insert('users', ['id' => 2, 'email' => 'viewer@example.com', 'password_hash' => 'unused', 'display_name' => null, 'status' => 'active', 'last_login_at' => null]);
        $connection->insert('organizations', ['id' => 99, 'name' => 'Route Test Org', 'slug' => 'route-test-org']);
        $connection->insert('first_party_sessions', ['user_id' => 1, 'session_token_hash' => hash('sha256', 'fixed-token'), 'expires_at' => '2099-01-01 00:00:00', 'revoked_at' => null, 'last_seen_at' => null]);
        $connection->insert('organization_members', ['id' => 1, 'organization_id' => 99, 'user_id' => 1, 'status' => 'active', 'title' => 'Owner']);
        $connection->insert('organization_members', ['id' => 2, 'organization_id' => 99, 'user_id' => 2, 'status' => 'active', 'title' => null]);
        $connection->insert('roles', ['id' => 1, 'organization_id' => 99, 'slug' => 'advertiser-owner', 'name' => 'Owner']);
        $connection->insert('roles', ['id' => 2, 'organization_id' => 99, 'slug' => 'viewer', 'name' => 'Viewer']);
        $connection->insert('roles', ['id' => 3, 'organization_id' => 99, 'slug' => 'campaign_manager', 'name' => 'Campaign Manager']);
        $connection->insert('roles', ['id' => 4, 'organization_id' => 99, 'slug' => 'finance', 'name' => 'Finance']);
        $connection->insert('permissions', ['id' => 1, 'slug' => Permission::OrganizationMembersRead, 'description' => 'Read members']);
        $connection->insert('permissions', ['id' => 2, 'slug' => Permission::OrganizationMembersManage, 'description' => 'Manage members']);
        if ($includePermission) {
            $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 1]);
        }
        if ($includeManagePermission) {
            $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 2]);
        }
        $connection->insert('role_permissions', ['role_id' => 2, 'permission_id' => 1]);
        $connection->insert('role_permissions', ['role_id' => 3, 'permission_id' => 1]);
        $connection->insert('role_permissions', ['role_id' => 4, 'permission_id' => 1]);
        $connection->insert('user_roles', ['user_id' => 1, 'role_id' => 1, 'organization_id' => 99]);
        $connection->insert('user_roles', ['user_id' => 2, 'role_id' => 2, 'organization_id' => 99]);

        return $connection;
    }
}
