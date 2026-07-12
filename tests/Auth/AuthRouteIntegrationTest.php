<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\Action\Auth\LoginAction;
use VertoAD\Http\Action\Auth\LogoutAction;
use VertoAD\Http\Action\Auth\MeAction;
use VertoAD\Http\Action\Auth\RegisterAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Error\OperationErrorHandler;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\Operations\InMemoryOperationErrorLogRepository;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\AuthService;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\PasswordHasher;

final class AuthRouteIntegrationTest extends TestCase
{
    public function testBearerTokenAuthenticatesMeAndLogoutRevokesSession(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Owner',
        ]);

        $login = $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
        ]);
        self::assertSame('fixed-token', $login['data']['token']['access_token']);

        $me = $this->handleJson($app, 'GET', '/api/v1/auth/me?organization_id=99', null, 'fixed-token');
        self::assertSame('owner@example.com', $me['data']['user']['email']);
        self::assertSame(99, $me['data']['organization_id']);
        self::assertSame(['advertiser-owner'], $me['data']['membership']['roles']);
        self::assertSame(['campaigns.manage'], $me['data']['membership']['permissions']);
        self::assertSame([
            [
                'id' => 88,
                'name' => 'Alpha Publisher',
                'slug' => 'alpha-publisher',
                'roles' => ['publisher-owner'],
                'permissions' => ['publisher.sites.manage'],
            ],
            [
                'id' => 99,
                'name' => 'Zeta Advertiser',
                'slug' => 'zeta-advertiser',
                'roles' => ['advertiser-owner'],
                'permissions' => ['campaigns.manage'],
            ],
        ], $me['data']['organizations']);

        $logout = $this->handleJson($app, 'POST', '/api/v1/auth/logout', [], 'fixed-token');
        self::assertTrue($logout['data']['revoked']);

        $afterLogout = $this->handleJson($app, 'GET', '/api/v1/auth/me?organization_id=99', null, 'fixed-token');
        self::assertSame('authentication_required', $afterLogout['error']['code']);
    }

    public function testMeReturnsEmptyOrganizationListWhenUserHasNoActiveMemberships(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'memberless@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Memberless',
        ]);
        $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'memberless@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $connection->executeStatement('DELETE FROM organization_members WHERE user_id = 1');

        $me = $this->handleJson($app, 'GET', '/api/v1/auth/me', null, 'fixed-token');

        self::assertNull($me['data']['organization_id']);
        self::assertNull($me['data']['membership']);
        self::assertSame([], $me['data']['organizations']);
    }

    public function testMePreservesInactiveOrForeignMembershipSelectionWithoutLeakingMembership(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Owner',
        ]);
        $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
        ]);

        foreach ([55, 66] as $organizationId) {
            $me = $this->handleJson(
                $app,
                'GET',
                '/api/v1/auth/me?organization_id=' . $organizationId,
                null,
                'fixed-token',
            );

            self::assertSame($organizationId, $me['data']['organization_id']);
            self::assertNull($me['data']['membership']);
            self::assertSame([88, 99], array_column($me['data']['organizations'], 'id'));
        }
    }

    public function testMeKeepsOrganizationListMembershipScopedForSuperAdministrators(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'root@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'Root',
        ]);
        $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'root@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $connection->insert('roles', [
            'id' => 100,
            'organization_id' => null,
            'slug' => 'super-admin',
            'name' => 'Super Admin',
        ]);
        $connection->insert('user_roles', [
            'user_id' => 1,
            'role_id' => 100,
            'organization_id' => null,
        ]);

        $me = $this->handleJson($app, 'GET', '/api/v1/auth/me', null, 'fixed-token');

        self::assertTrue($me['data']['user']['is_super_admin']);
        self::assertSame([88, 99], array_column($me['data']['organizations'], 'id'));
        self::assertNotContains(66, array_column($me['data']['organizations'], 'id'));
    }

    public function testMeDatabaseFailureReturnsCentralizedOperationErrorEnvelope(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $this->handleJson($app, 'POST', '/api/v1/auth/register', [
            'email' => 'db-error@example.com',
            'password' => 'correct horse battery staple',
            'display_name' => 'DB Error',
        ]);
        $this->handleJson($app, 'POST', '/api/v1/auth/login', [
            'email' => 'db-error@example.com',
            'password' => 'correct horse battery staple',
        ]);
        $connection->executeStatement('DROP TABLE organizations');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/me')
            ->withHeader('Authorization', 'Bearer fixed-token');
        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(500, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertSame('operation_error', $payload['error']['code']);
        self::assertStringStartsWith('operr_', $payload['error']['operation_error_id']);
        self::assertSame('v1', $payload['meta']['api_version']);
        self::assertSame($payload['request_id'], $response->getHeaderLine('X-Request-Id'));
    }

    public function testMalformedJsonRegisterReturnsEnvelopeValidationError(): void
    {
        $app = $this->createApp($this->createConnection());
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/register')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream('{'));

        $decoded = json_decode((string) $app->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNull($decoded['data']);
        self::assertSame('invalid_request', $decoded['error']['code']);
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            Connection::class => $connection,
            PasswordHasher::class => static fn (): PasswordHasher => new PasswordHasher(),
            PasswordResetTokenRepositoryInterface::class => static fn (): PasswordResetTokenRepositoryInterface =>
                new PasswordResetTokenRepository($connection),
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new FirstPartySessionRepository($connection),
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new OrganizationMembershipRepository($connection),
            AuthService::class => static fn (
                Connection $db,
                PasswordHasher $hasher,
                PasswordResetTokenRepositoryInterface $resetTokens,
                FirstPartySessionRepositoryInterface $sessions,
            ): AuthService => new AuthService($db, $hasher, $resetTokens, $sessions, static fn (): string => 'fixed-token'),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/auth/register', RegisterAction::class);
        $app->post('/api/v1/auth/login', LoginAction::class);
        $app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/auth/logout', LogoutAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware(false, true, true);
        $errorMiddleware->setDefaultErrorHandler(new OperationErrorHandler(
            $app->getResponseFactory(),
            new OperationErrorCaptureService(
                new InMemoryOperationErrorLogRepository(),
                new AuditLogService(new class implements AuditLogRepositoryInterface {
                    public function append(AuditLogEntry $entry): void
                    {
                    }
                }),
            ),
        ));

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(
        \Slim\App $app,
        string $method,
        string $uri,
        ?array $payload,
        ?string $bearerToken = null,
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $decoded = json_decode((string) $app->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "active",
                last_login_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                requested_ip BLOB NULL,
                user_agent TEXT NULL,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE first_party_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                revoked_at TEXT NULL,
                last_seen_at TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE organizations (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                billing_status TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE organization_members (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                title TEXT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                organization_id INTEGER NULL,
                slug TEXT NOT NULL,
                name TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL,
                description TEXT NULL
            )',
        );
        $connection->executeStatement('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL)');
        $connection->insert('organizations', [
            'id' => 99,
            'name' => 'Zeta Advertiser',
            'slug' => 'zeta-advertiser',
            'billing_status' => 'active',
        ]);
        $connection->insert('organizations', [
            'id' => 88,
            'name' => 'Alpha Publisher',
            'slug' => 'alpha-publisher',
            'billing_status' => 'active',
        ]);
        $connection->insert('organizations', [
            'id' => 77,
            'name' => 'Suspended Member',
            'slug' => 'suspended-member',
            'billing_status' => 'suspended',
        ]);
        $connection->insert('organizations', [
            'id' => 66,
            'name' => 'Unrelated Organization',
            'slug' => 'unrelated-organization',
            'billing_status' => 'active',
        ]);
        $connection->insert('organizations', [
            'id' => 55,
            'name' => 'Invited Organization',
            'slug' => 'invited-organization',
            'billing_status' => 'active',
        ]);
        $connection->insert('organization_members', [
            'id' => 1,
            'organization_id' => 99,
            'user_id' => 1,
            'status' => 'active',
            'title' => null,
        ]);
        $connection->insert('organization_members', [
            'id' => 2,
            'organization_id' => 88,
            'user_id' => 1,
            'status' => 'active',
            'title' => null,
        ]);
        $connection->insert('organization_members', [
            'id' => 3,
            'organization_id' => 77,
            'user_id' => 1,
            'status' => 'active',
            'title' => null,
        ]);
        $connection->insert('organization_members', [
            'id' => 4,
            'organization_id' => 55,
            'user_id' => 1,
            'status' => 'invited',
            'title' => null,
        ]);
        $connection->insert('roles', [
            'id' => 1,
            'organization_id' => 99,
            'slug' => 'advertiser-owner',
            'name' => 'Advertiser Owner',
        ]);
        $connection->insert('roles', [
            'id' => 2,
            'organization_id' => 88,
            'slug' => 'publisher-owner',
            'name' => 'Publisher Owner',
        ]);
        $connection->insert('permissions', [
            'id' => 1,
            'slug' => 'campaigns.manage',
            'description' => 'Manage campaigns',
        ]);
        $connection->insert('permissions', [
            'id' => 2,
            'slug' => 'publisher.sites.manage',
            'description' => 'Manage publisher sites',
        ]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 1]);
        $connection->insert('role_permissions', ['role_id' => 2, 'permission_id' => 2]);
        $connection->insert('user_roles', ['user_id' => 1, 'role_id' => 1, 'organization_id' => 99]);
        $connection->insert('user_roles', ['user_id' => 1, 'role_id' => 2, 'organization_id' => 88]);

        return $connection;
    }
}
