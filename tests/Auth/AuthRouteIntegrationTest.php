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
use VertoAD\Http\Action\Auth\LoginAction;
use VertoAD\Http\Action\Auth\LogoutAction;
use VertoAD\Http\Action\Auth\MeAction;
use VertoAD\Http\Action\Auth\RegisterAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;
use VertoAD\Service\AuthService;
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

        $logout = $this->handleJson($app, 'POST', '/api/v1/auth/logout', [], 'fixed-token');
        self::assertTrue($logout['data']['revoked']);

        $afterLogout = $this->handleJson($app, 'GET', '/api/v1/auth/me?organization_id=99', null, 'fixed-token');
        self::assertSame('authentication_required', $afterLogout['error']['code']);
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
        $app->addErrorMiddleware(false, true, true);

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
        $connection->insert('organization_members', [
            'id' => 1,
            'organization_id' => 99,
            'user_id' => 1,
            'status' => 'active',
            'title' => null,
        ]);
        $connection->insert('roles', [
            'id' => 1,
            'organization_id' => 99,
            'slug' => 'advertiser-owner',
            'name' => 'Advertiser Owner',
        ]);
        $connection->insert('permissions', [
            'id' => 1,
            'slug' => 'campaigns.manage',
            'description' => 'Manage campaigns',
        ]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 1]);
        $connection->insert('user_roles', ['user_id' => 1, 'role_id' => 1, 'organization_id' => 99]);

        return $connection;
    }
}
