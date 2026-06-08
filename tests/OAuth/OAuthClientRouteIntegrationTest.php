<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\OAuth\CreateOAuthClientAction;
use VertoAD\Http\Action\OAuth\ListOAuthClientsAction;
use VertoAD\Http\Action\OAuth\RotateOAuthClientSecretAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class OAuthClientRouteIntegrationTest extends TestCase
{
    public function testOrganizationCanCreateListAndRotateOAuthClientSecretWithoutLeakingStoredSecret(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $created = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Reporting Exporter',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code', 'client_credentials'],
            'scopes' => ['campaign.read.own', 'report.read.own'],
            'is_confidential' => true,
        ]);

        self::assertSame(201, $created['status']);
        self::assertSame(99, $created['body']['data']['client']['organization_id']);
        self::assertSame('Reporting Exporter', $created['body']['data']['client']['name']);
        self::assertSame('secret-v1', $created['body']['data']['client_secret']);
        self::assertArrayNotHasKey('secret_hash', $created['body']['data']['client']);
        self::assertStringStartsWith('vocs_', $created['body']['data']['client']['client_id']);
        self::assertNotSame('secret-v1', $connection->fetchOne('SELECT secret_hash FROM oauth_clients'));

        $listed = $this->handleJson($app, 'GET', '/api/v1/oauth/clients?organization_id=99', null);

        self::assertSame(200, $listed['status']);
        self::assertCount(1, $listed['body']['data']['clients']);
        self::assertSame($created['body']['data']['client']['client_id'], $listed['body']['data']['clients'][0]['client_id']);
        self::assertArrayNotHasKey('client_secret', $listed['body']['data']['clients'][0]);
        self::assertArrayNotHasKey('secret_hash', $listed['body']['data']['clients'][0]);

        $rotated = $this->handleJson(
            $app,
            'POST',
            '/api/v1/oauth/clients/' . $created['body']['data']['client']['client_id'] . '/rotate-secret?organization_id=99',
            [],
        );

        self::assertSame(200, $rotated['status']);
        self::assertSame('secret-v2', $rotated['body']['data']['client_secret']);
        self::assertNotSame('secret-v2', $connection->fetchOne('SELECT secret_hash FROM oauth_clients'));
    }

    public function testCreateOAuthClientRequiresPositiveOrganizationScope(): void
    {
        $app = $this->createApp($this->createConnection());

        $response = $this->handleJson($app, 'POST', '/api/v1/oauth/clients', [
            'name' => 'No Scope',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code'],
            'scopes' => ['campaign.read.own'],
        ]);

        self::assertSame(400, $response['status']);
        self::assertSame('organization_scope_required', $response['body']['error']['code']);
    }

    public function testOAuthClientActionsReturnEnvelopeErrorsForInvalidRequests(): void
    {
        $app = $this->createApp($this->createConnection());

        $listWithoutScope = $this->handleJson($app, 'GET', '/api/v1/oauth/clients', null);
        self::assertSame(400, $listWithoutScope['status']);
        self::assertSame('organization_scope_required', $listWithoutScope['body']['error']['code']);

        $missingPayload = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', null);
        self::assertSame(422, $missingPayload['status']);
        self::assertSame('invalid_request', $missingPayload['body']['error']['code']);

        $missingName = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code'],
        ]);
        self::assertSame(422, $missingName['status']);
        self::assertSame('invalid_oauth_client', $missingName['body']['error']['code']);

        $badRedirectUris = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Bad Redirects',
            'redirect_uris' => 'https://developer.example.com/callback',
            'grant_types' => ['authorization_code'],
        ]);
        self::assertSame(422, $badRedirectUris['status']);
        self::assertSame('invalid_oauth_client', $badRedirectUris['body']['error']['code']);

        $emptyGrantTypes = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'No Grants',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => [],
        ]);
        self::assertSame(422, $emptyGrantTypes['status']);
        self::assertSame('invalid_oauth_client', $emptyGrantTypes['body']['error']['code']);

        $nonStringGrantTypes = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Non String Grants',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => [false],
        ]);
        self::assertSame(422, $nonStringGrantTypes['status']);
        self::assertSame('invalid_oauth_client', $nonStringGrantTypes['body']['error']['code']);

        $badScopes = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Bad Scopes',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code'],
            'scopes' => 'campaign.read.own',
        ]);
        self::assertSame(422, $badScopes['status']);
        self::assertSame('invalid_oauth_client', $badScopes['body']['error']['code']);

        $missingClient = $this->handleJson(
            $app,
            'POST',
            '/api/v1/oauth/clients/missing-client/rotate-secret?organization_id=99',
            [],
        );
        self::assertSame(404, $missingClient['status']);
        self::assertSame('oauth_client_not_found', $missingClient['body']['error']['code']);
    }

    public function testOAuthClientActionsGuardOrganizationScopeWithoutRouteMiddleware(): void
    {
        $connection = $this->createConnection();
        $repository = new OAuthClientRepository($connection);
        $secrets = new OAuthClientSecretHasher($this->sequentialSecrets());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/oauth/clients')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(1, 'developer@example.com', false), null),
            );
        $responseFactory = new ResponseFactory();

        $list = (new ListOAuthClientsAction($repository))($request, $responseFactory->createResponse());
        self::assertSame(400, $list->getStatusCode());

        $create = (new CreateOAuthClientAction($repository, $secrets))(
            $request->withParsedBody([
                'name' => 'No Scope',
                'redirect_uris' => ['https://developer.example.com/callback'],
                'grant_types' => ['authorization_code'],
            ]),
            $responseFactory->createResponse(),
        );
        self::assertSame(400, $create->getStatusCode());

        $rotate = (new RotateOAuthClientSecretAction($repository, $secrets))(
            $request,
            $responseFactory->createResponse(),
            ['client_id' => 'vocs_missing'],
        );
        self::assertSame(400, $rotate->getStatusCode());
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $secretFactory = $this->sequentialSecrets();
        $container = (new ContainerBuilder())->addDefinitions([
            Connection::class => $connection,
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
            OAuthClientRepositoryInterface::class => static fn (): OAuthClientRepositoryInterface =>
                new OAuthClientRepository($connection),
            OAuthClientSecretHasher::class => static fn (): OAuthClientSecretHasher =>
                new OAuthClientSecretHasher($secretFactory),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $permission = new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forOrganization('sdk.oauth_client.write.own'),
        );
        $app->get('/api/v1/oauth/clients', ListOAuthClientsAction::class)
            ->add($permission)
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/oauth/clients', CreateOAuthClientAction::class)
            ->add($permission)
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/oauth/clients/{client_id}/rotate-secret', RotateOAuthClientSecretAction::class)
            ->add($permission)
            ->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function handleJson(\Slim\App $app, string $method, string $uri, ?array $payload): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withHeader('Authorization', 'Bearer fixed-token');
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
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
        $connection->executeStatement('CREATE TABLE permissions (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, description TEXT NULL)');
        $connection->executeStatement('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL)');
        $connection->executeStatement(
            'CREATE TABLE oauth_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NULL,
                owner_user_id INTEGER NULL,
                client_identifier TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                secret_hash TEXT NULL,
                redirect_uris_json TEXT NOT NULL,
                grant_types_json TEXT NOT NULL,
                scopes_json TEXT NULL,
                is_confidential INTEGER NOT NULL DEFAULT 1,
                revoked_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->insert('first_party_sessions', [
            'user_id' => 1,
            'session_token_hash' => hash('sha256', 'fixed-token'),
            'expires_at' => '2099-01-01 00:00:00',
            'revoked_at' => null,
            'last_seen_at' => null,
        ]);
        $connection->insert('users', [
            'id' => 1,
            'email' => 'developer@example.com',
            'password_hash' => 'unused',
            'display_name' => 'Developer',
            'status' => 'active',
            'last_login_at' => null,
        ]);
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
            'slug' => 'developer',
            'name' => 'Developer',
        ]);
        $connection->insert('permissions', [
            'id' => 1,
            'slug' => 'sdk.oauth_client.write.own',
            'description' => 'Manage own OAuth clients',
        ]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 1]);
        $connection->insert('user_roles', ['user_id' => 1, 'role_id' => 1, 'organization_id' => 99]);

        return $connection;
    }

    private function sequentialSecrets(): callable
    {
        $secrets = ['secret-v1', 'secret-v2'];

        return static function () use (&$secrets): string {
            return (string) array_shift($secrets);
        };
    }
}
