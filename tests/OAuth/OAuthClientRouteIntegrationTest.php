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
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Http\Action\OAuth\CreateOAuthClientAction;
use VertoAD\Http\Action\OAuth\ListOAuthClientsAction;
use VertoAD\Http\Action\OAuth\RotateOAuthClientSecretAction;
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
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthScopeCatalog;
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
        ], ['X-Request-Id' => 'req-oauth-client-create']);

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
            ['X-Request-Id' => 'req-oauth-client-rotate'],
        );

        self::assertSame(200, $rotated['status']);
        self::assertSame('secret-v2', $rotated['body']['data']['client_secret']);
        self::assertNotSame('secret-v2', $connection->fetchOne('SELECT secret_hash FROM oauth_clients'));

        $auditRows = $connection->fetchAllAssociative('SELECT * FROM audit_logs ORDER BY id');
        self::assertCount(2, $auditRows);
        self::assertSame('sdk.oauth_client.create', $auditRows[0]['action']);
        self::assertSame('oauth_client', $auditRows[0]['subject_type']);
        self::assertSame((int) $connection->fetchOne('SELECT id FROM oauth_clients'), (int) $auditRows[0]['subject_id']);
        self::assertSame(99, (int) $auditRows[0]['organization_id']);
        self::assertSame(1, (int) $auditRows[0]['actor_user_id']);
        self::assertSame('OAuthClientRouteIntegrationTest/1.0', $auditRows[0]['user_agent']);
        self::assertSame('req-oauth-client-create', $auditRows[0]['request_id']);
        self::assertStringContainsString('report.read.own', (string) $auditRows[0]['metadata_json']);
        self::assertStringNotContainsString('secret-v1', (string) $auditRows[0]['metadata_json']);
        self::assertStringNotContainsString('secret-v2', (string) $auditRows[0]['metadata_json']);
        self::assertStringNotContainsString((string) $connection->fetchOne('SELECT secret_hash FROM oauth_clients'), (string) $auditRows[0]['metadata_json']);
        self::assertSame('sdk.oauth_client.rotate_secret', $auditRows[1]['action']);
        self::assertSame('oauth_client', $auditRows[1]['subject_type']);
        self::assertSame((int) $connection->fetchOne('SELECT id FROM oauth_clients'), (int) $auditRows[1]['subject_id']);
        self::assertSame('req-oauth-client-rotate', $auditRows[1]['request_id']);
        self::assertStringNotContainsString('secret-v2', (string) $auditRows[1]['metadata_json']);
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

    public function testCreateRejectsNonCatalogAndCreatorUnauthorizedScopes(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $base = [
            'name' => 'Overprivileged Client',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['client_credentials'],
            'is_confidential' => true,
        ];

        $managementScope = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', $base + [
            'scopes' => ['organizations.members.manage'],
        ]);
        self::assertSame(422, $managementScope['status']);
        self::assertSame('invalid_oauth_client', $managementScope['body']['error']['code']);
        self::assertStringContainsString('not available through the advertiser Open API', $managementScope['body']['error']['message']);

        $missingCreatorPermission = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', $base + [
            'scopes' => ['campaign.write.own'],
        ]);
        self::assertSame(422, $missingCreatorPermission['status']);
        self::assertSame('invalid_oauth_client', $missingCreatorPermission['body']['error']['code']);
        self::assertStringContainsString("exceeds the creator's permissions", $missingCreatorPermission['body']['error']['message']);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_clients'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testPublicPkceClientHasNoSecretAndCannotUseClientCredentialsOrRotateSecret(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);
        $created = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Public PKCE Client',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'scopes' => ['report.read.own'],
            'is_confidential' => false,
        ]);

        self::assertSame(201, $created['status']);
        self::assertFalse($created['body']['data']['client']['is_confidential']);
        self::assertArrayNotHasKey('client_secret', $created['body']['data']);
        self::assertNull($connection->fetchOne('SELECT secret_hash FROM oauth_clients'));

        $clientCredentials = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Unsafe Public Client',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code', 'client_credentials'],
            'scopes' => ['report.read.own'],
            'is_confidential' => false,
        ]);
        self::assertSame(422, $clientCredentials['status']);
        self::assertStringContainsString('cannot use the client_credentials grant', $clientCredentials['body']['error']['message']);

        $rotate = $this->handleJson(
            $app,
            'POST',
            '/api/v1/oauth/clients/' . $created['body']['data']['client']['client_id'] . '/rotate-secret?organization_id=99',
            [],
        );
        self::assertSame(422, $rotate['status']);
        self::assertSame('oauth_client_is_public', $rotate['body']['error']['code']);
        self::assertNull($connection->fetchOne('SELECT secret_hash FROM oauth_clients'));
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

        $badConfidentialFlag = $this->handleJson($app, 'POST', '/api/v1/oauth/clients?organization_id=99', [
            'name' => 'Bad Confidential Flag',
            'redirect_uris' => ['https://developer.example.com/callback'],
            'grant_types' => ['authorization_code'],
            'is_confidential' => 'false',
        ]);
        self::assertSame(422, $badConfidentialFlag['status']);
        self::assertSame('invalid_oauth_client', $badConfidentialFlag['body']['error']['code']);

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

        $create = (new CreateOAuthClientAction(
            $repository,
            $secrets,
            new OAuthScopeCatalog(new OrganizationMembershipRepository($connection), new PermissionMatcher()),
            new AuditLogService(new CapturingOAuthClientAuditRepository()),
            new ClientIpResolver(),
        ))(
            $request->withParsedBody([
                'name' => 'No Scope',
                'redirect_uris' => ['https://developer.example.com/callback'],
                'grant_types' => ['authorization_code'],
            ]),
            $responseFactory->createResponse(),
        );
        self::assertSame(400, $create->getStatusCode());

        $machineRequest = $request->withAttribute(
            RequestUserContext::ATTRIBUTE,
            new RequestUserContext(
                organizationId: 99,
                oauthToken: new OAuthAccessTokenContext(1, 1, 'machine-client', 99, null, ['sdk.oauth_client.write.own']),
            ),
        );
        $machineCreate = (new CreateOAuthClientAction(
            $repository,
            $secrets,
            new OAuthScopeCatalog(new OrganizationMembershipRepository($connection), new PermissionMatcher()),
            new AuditLogService(new CapturingOAuthClientAuditRepository()),
            new ClientIpResolver(),
        ))(
            $machineRequest->withParsedBody([
                'name' => 'Machine Created Client',
                'redirect_uris' => ['https://developer.example.com/callback'],
                'grant_types' => ['authorization_code'],
            ]),
            $responseFactory->createResponse(),
        );
        self::assertSame(401, $machineCreate->getStatusCode());

        $rotate = (new RotateOAuthClientSecretAction(
            $repository,
            $secrets,
            new AuditLogService(new CapturingOAuthClientAuditRepository()),
            new ClientIpResolver(),
        ))(
            $request,
            $responseFactory->createResponse(),
            ['client_id' => 'vocs_missing'],
        );
        self::assertSame(400, $rotate->getStatusCode());
    }

    public function testRotateOAuthClientSecretReturnsNotFoundWhenSecretUpdateMissesExistingClient(): void
    {
        $client = new OAuthClient(
            id: 123,
            organizationId: 99,
            ownerUserId: 1,
            clientIdentifier: 'vocs_race',
            name: 'Race Client',
            secretHash: 'old-secret-hash',
            redirectUris: ['https://developer.example.com/callback'],
            grantTypes: ['authorization_code'],
            scopes: ['sdk.oauth_client.read.own'],
            isConfidential: true,
            revokedAt: null,
        );
        $repository = new class ($client) implements OAuthClientRepositoryInterface {
            public int $transactionCalls = 0;
            public bool $rotateCalled = false;

            public function __construct(private readonly OAuthClient $client)
            {
            }

            public function transactional(callable $operation): mixed
            {
                $this->transactionCalls++;

                return $operation();
            }

            public function store(OAuthClient $client): OAuthClient
            {
                throw new \LogicException('OAuth client storage is not used by this test.');
            }

            public function findActiveByIdentifier(string $clientIdentifier): ?OAuthClient
            {
                return $clientIdentifier === $this->client->clientIdentifier ? $this->client : null;
            }

            public function listActiveForOrganization(int $organizationId): array
            {
                throw new \LogicException('OAuth client listing is not used by this test.');
            }

            public function rotateSecret(string $clientIdentifier, string $secretHash): bool
            {
                $this->rotateCalled = true;

                return false;
            }
        };
        $auditRepository = new CapturingOAuthClientAuditRepository();
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/oauth/clients/vocs_race/rotate-secret?organization_id=99')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(1, 'developer@example.com', false), 99),
            );

        $response = (new RotateOAuthClientSecretAction(
            $repository,
            new OAuthClientSecretHasher(static fn (): string => 'rotated-secret'),
            new AuditLogService($auditRepository),
            new ClientIpResolver(),
        ))($request, (new ResponseFactory())->createResponse(), ['client_id' => 'vocs_race']);

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('oauth_client_not_found', $body['code']);
        self::assertSame(1, $repository->transactionCalls);
        self::assertTrue($repository->rotateCalled);
        self::assertSame([], $auditRepository->entries);
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
            PermissionMatcher::class => static fn (): PermissionMatcher => new PermissionMatcher(),
            TenantAccessService::class => static fn (
                OrganizationMembershipRepositoryInterface $memberships,
                PermissionMatcher $permissions,
            ): TenantAccessService => new TenantAccessService($memberships, $permissions),
            OAuthScopeCatalog::class => static fn (
                OrganizationMembershipRepositoryInterface $memberships,
                PermissionMatcher $permissions,
            ): OAuthScopeCatalog => new OAuthScopeCatalog($memberships, $permissions),
            OAuthClientRepositoryInterface::class => static fn (): OAuthClientRepositoryInterface =>
                new OAuthClientRepository($connection),
            OAuthClientSecretHasher::class => static fn (): OAuthClientSecretHasher =>
                new OAuthClientSecretHasher($secretFactory),
            AuditLogRepositoryInterface::class => static fn (): AuditLogRepositoryInterface =>
                new AuditLogRepository($connection),
            AuditLogService::class => static fn (AuditLogRepositoryInterface $repository): AuditLogService =>
                new AuditLogService($repository),
            ClientIpResolver::class => static fn (): ClientIpResolver => new ClientIpResolver(),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $readPermission = new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forOrganization('sdk.oauth_client.read.own'),
        );
        $writePermission = new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forOrganization('sdk.oauth_client.write.own'),
        );
        $rotatePermission = new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forOrganization('sdk.oauth_client.rotate_secret.own'),
        );
        $app->get('/api/v1/oauth/clients', ListOAuthClientsAction::class)
            ->add($readPermission)
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/oauth/clients', CreateOAuthClientAction::class)
            ->add($writePermission)
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/oauth/clients/{client_id}/rotate-secret', RotateOAuthClientSecretAction::class)
            ->add($rotatePermission)
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
    private function handleJson(\Slim\App $app, string $method, string $uri, ?array $payload, array $headers = []): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withHeader('Authorization', 'Bearer fixed-token')
            ->withHeader('User-Agent', 'OAuthClientRouteIntegrationTest/1.0');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader((string) $name, (string) $value);
        }
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
        $connection->executeStatement(
            'CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NULL,
                actor_user_id INTEGER NULL,
                action TEXT NOT NULL,
                subject_type TEXT NOT NULL,
                subject_id INTEGER NULL,
                ip_address BLOB NULL,
                user_agent TEXT NULL,
                request_id TEXT NULL,
                metadata_json TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
            'slug' => 'sdk.oauth_client.read.own',
            'description' => 'View own OAuth clients',
        ]);
        $connection->insert('permissions', [
            'id' => 2,
            'slug' => 'sdk.oauth_client.write.own',
            'description' => 'Create own OAuth clients',
        ]);
        $connection->insert('permissions', [
            'id' => 3,
            'slug' => 'sdk.oauth_client.rotate_secret.own',
            'description' => 'Rotate own OAuth client secrets',
        ]);
        $connection->insert('permissions', [
            'id' => 4,
            'slug' => 'campaign.read.own',
            'description' => 'Read own campaigns',
        ]);
        $connection->insert('permissions', [
            'id' => 5,
            'slug' => 'report.read.own',
            'description' => 'Read own reports',
        ]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 1]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 2]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 3]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 4]);
        $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => 5]);
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

final class CapturingOAuthClientAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
