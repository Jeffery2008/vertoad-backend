<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Defuse\Crypto\Key;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Webhooks\CreateWebhookEndpointAction;
use VertoAD\Http\Action\Webhooks\ListWebhookDeliveriesAction;
use VertoAD\Http\Action\Webhooks\ListWebhookEndpointsAction;
use VertoAD\Http\Action\Webhooks\RotateWebhookEndpointSecretAction;
use VertoAD\Http\Action\Webhooks\TestWebhookEndpointAction;
use VertoAD\Http\Action\Webhooks\UpdateWebhookEndpointAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;
use VertoAD\Repository\Webhooks\InMemoryWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipher;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;

final class WebhookEndpointRouteIntegrationTest extends TestCase
{
    public function testOrganizationCanCreateListPatchRotateTestAndListEndpointDeliveriesWithoutLeakingSecrets(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp($connection);

        $created = $this->handleJson($app, 'POST', '/api/v1/webhooks/endpoints?organization_id=99', [
            'name' => '  Review Hook  ',
            'endpoint_url' => '  https://hooks.example/vertoad  ',
            'events' => ['review.approved', ' billing.points_changed ', 'review.approved'],
            'enabled' => true,
        ]);

        self::assertSame(201, $created['status']);
        self::assertSame('Review Hook', $created['body']['data']['endpoint']['name']);
        self::assertSame('https://hooks.example/vertoad', $created['body']['data']['endpoint']['endpoint_url']);
        self::assertSame(['billing.points_changed', 'review.approved'], $created['body']['data']['endpoint']['events']);
        self::assertSame('active', $created['body']['data']['endpoint']['status']);
        self::assertTrue($created['body']['data']['endpoint']['enabled']);
        self::assertSame('whsec_route_secret_v1', $created['body']['data']['signing_secret']);
        self::assertStringStartsWith('whe_', $created['body']['data']['endpoint']['endpoint_id']);
        self::assertArrayNotHasKey('encrypted_signing_secret', $created['body']['data']['endpoint']);
        self::assertArrayNotHasKey('secret_hash', $created['body']['data']['endpoint']);
        self::assertStringNotContainsString('whsec_route_secret_v1', $created['body']['data']['endpoint']['secret_preview']);
        self::assertNotSame(
            'whsec_route_secret_v1',
            $connection->fetchOne('SELECT encrypted_signing_secret FROM webhook_endpoints'),
        );

        $endpointId = (string) $created['body']['data']['endpoint']['endpoint_id'];
        $listed = $this->handleJson($app, 'GET', '/api/v1/webhooks/endpoints?organization_id=99', null);
        self::assertSame(200, $listed['status']);
        self::assertCount(1, $listed['body']['data']['endpoints']);
        self::assertSame($endpointId, $listed['body']['data']['endpoints'][0]['endpoint_id']);
        $this->assertDoesNotExposeSecretMaterial($listed['body'], ['whsec_route_secret_v1']);

        $patched = $this->handleJson($app, 'PATCH', '/api/v1/webhooks/endpoints/' . $endpointId . '?organization_id=99', [
            'name' => 'Billing Hook',
            'endpoint_url' => 'https://hooks.example/billing',
            'events' => ['billing.points_changed'],
            'enabled' => false,
        ]);

        self::assertSame(200, $patched['status']);
        self::assertSame('Billing Hook', $patched['body']['data']['endpoint']['name']);
        self::assertSame('paused', $patched['body']['data']['endpoint']['status']);
        self::assertFalse($patched['body']['data']['endpoint']['enabled']);
        self::assertSame(['billing.points_changed'], $patched['body']['data']['endpoint']['events']);
        $this->assertDoesNotExposeSecretMaterial($patched['body'], ['whsec_route_secret_v1']);

        $rotated = $this->handleJson($app, 'POST', '/api/v1/webhooks/endpoints/' . $endpointId . '/rotate-secret?organization_id=99', []);
        self::assertSame(200, $rotated['status']);
        self::assertSame('whsec_route_secret_v2', $rotated['body']['data']['signing_secret']);
        self::assertArrayNotHasKey('encrypted_signing_secret', $rotated['body']['data']['endpoint']);

        $tested = $this->handleJson($app, 'POST', '/api/v1/webhooks/endpoints/' . $endpointId . '/test?organization_id=99', []);
        self::assertSame(202, $tested['status']);
        self::assertSame($endpointId, $tested['body']['data']['delivery']['endpoint_id']);
        self::assertSame(99, $tested['body']['data']['delivery']['organization_id']);
        self::assertSame('webhook.test', $tested['body']['data']['delivery']['event_type']);
        self::assertSame('queued', $tested['body']['data']['delivery']['status']);
        $this->assertDoesNotExposeSecretMaterial($tested['body'], ['whsec_route_secret_v1', 'whsec_route_secret_v2']);

        $deliveries = $this->handleJson(
            $app,
            'GET',
            '/api/v1/webhooks/deliveries?organization_id=99&endpoint_id=' . rawurlencode($endpointId) . '&status=queued&limit=5',
            null,
        );

        self::assertSame(200, $deliveries['status']);
        self::assertCount(1, $deliveries['body']['data']['deliveries']);
        self::assertSame($tested['body']['data']['delivery']['delivery_id'], $deliveries['body']['data']['deliveries'][0]['delivery_id']);
        self::assertSame($endpointId, $deliveries['body']['data']['deliveries'][0]['endpoint_id']);
        self::assertArrayNotHasKey('encrypted_signing_secret', $deliveries['body']['data']['deliveries'][0]);
        $this->assertDoesNotExposeSecretMaterial($deliveries['body'], ['whsec_route_secret_v1', 'whsec_route_secret_v2']);
    }

    public function testEndpointRoutesReturnStableEnvelopeErrorsForInvalidScopePayloadsAndCrossOrganizationAccess(): void
    {
        $app = $this->createApp($this->createConnection());

        $missingScope = $this->handleJson($app, 'GET', '/api/v1/webhooks/endpoints', null);
        self::assertSame(400, $missingScope['status']);
        self::assertSame('organization_scope_required', $missingScope['body']['error']['code']);

        $invalidBody = $this->handleJson($app, 'POST', '/api/v1/webhooks/endpoints?organization_id=99', null);
        self::assertSame(422, $invalidBody['status']);
        self::assertSame('invalid_request', $invalidBody['body']['error']['code']);

        foreach ([
            ['endpoint_url' => 'http://hooks.example/vertoad', 'events' => ['review.approved']],
            ['endpoint_url' => 'https://localhost/vertoad', 'events' => ['review.approved']],
            ['endpoint_url' => 'https://127.0.0.1/vertoad', 'events' => ['review.approved']],
            ['endpoint_url' => 'https://hooks.example/vertoad', 'events' => []],
            ['endpoint_url' => 'https://hooks.example/vertoad', 'events' => 'review.approved'],
        ] as $payload) {
            $response = $this->handleJson($app, 'POST', '/api/v1/webhooks/endpoints?organization_id=99', [
                'name' => 'Invalid',
                'enabled' => true,
                ...$payload,
            ]);
            self::assertSame(422, $response['status']);
            self::assertSame('invalid_webhook_endpoint', $response['body']['error']['code']);
        }

        $created = $this->handleJson($app, 'POST', '/api/v1/webhooks/endpoints?organization_id=99', [
            'name' => 'Org 99',
            'endpoint_url' => 'https://hooks.example/org-99',
            'events' => ['review.approved'],
            'enabled' => true,
        ]);
        $endpointId = (string) $created['body']['data']['endpoint']['endpoint_id'];

        foreach ([
            ['PATCH', '/api/v1/webhooks/endpoints/' . $endpointId . '?organization_id=100', ['name' => 'wrong org']],
            ['POST', '/api/v1/webhooks/endpoints/' . $endpointId . '/rotate-secret?organization_id=100', []],
            ['POST', '/api/v1/webhooks/endpoints/' . $endpointId . '/test?organization_id=100', []],
        ] as [$method, $uri, $payload]) {
            $response = $this->handleJson($app, $method, $uri, $payload);
            self::assertSame(404, $response['status']);
            self::assertSame('webhook_endpoint_not_found', $response['body']['error']['code']);
        }

        $deliveries = $this->handleJson($app, 'GET', '/api/v1/webhooks/deliveries?organization_id=100&endpoint_id=' . $endpointId, null);
        self::assertSame(200, $deliveries['status']);
        self::assertSame([], $deliveries['body']['data']['deliveries']);
    }

    public function testWebhookEndpointRoutesDeclareSensitivePermissionMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/config/routes.php');

        foreach ([
            ['get', '/api/v1/webhooks/endpoints', 'webhook.read.own'],
            ['post', '/api/v1/webhooks/endpoints', 'webhook.write.own'],
            ['patch', '/api/v1/webhooks/endpoints/{endpoint_id}', 'webhook.write.own'],
            ['post', '/api/v1/webhooks/endpoints/{endpoint_id}/rotate-secret', 'webhook.secret.rotate.own'],
            ['post', '/api/v1/webhooks/endpoints/{endpoint_id}/test', 'webhook.write.own'],
            ['get', '/api/v1/webhooks/deliveries', 'webhook.delivery.read.own'],
        ] as [$method, $route, $permission]) {
            $pattern = preg_quote("\$app->{$method}('{$route}'", '/')
                . '(?s:.{0,260})'
                . preg_quote("->add(\$permission('{$permission}'))", '/');
            self::assertSame(1, preg_match('/' . $pattern . '/', $routes), "{$route} must enforce {$permission}.");
        }
    }

    public function testDirectWebhookActionsReturnAuthenticationGuardResponses(): void
    {
        $endpoints = new InMemoryWebhookEndpointRepository();
        $deliveries = new InMemoryWebhookDeliveryRepository();
        $cipher = new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString());
        $responseFactory = new ResponseFactory();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/webhooks/endpoints');

        foreach ([
            [new ListWebhookEndpointsAction($endpoints), []],
            [new CreateWebhookEndpointAction($endpoints, $cipher), []],
            [new UpdateWebhookEndpointAction($endpoints), ['endpoint_id' => 'whe_missing']],
            [new RotateWebhookEndpointSecretAction($endpoints, $cipher), ['endpoint_id' => 'whe_missing']],
            [new TestWebhookEndpointAction($endpoints, $deliveries), ['endpoint_id' => 'whe_missing']],
            [new ListWebhookDeliveriesAction($deliveries), []],
        ] as [$action, $args]) {
            $response = $action($request, $responseFactory->createResponse(), $args);
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(401, $response->getStatusCode());
            self::assertSame('authentication_required', $payload['code'] ?? null);
        }
    }

    public function testDirectWebhookActionsValidateRawPayloadsAndDeliveryLimitTypes(): void
    {
        $endpoints = new InMemoryWebhookEndpointRepository();
        $deliveries = new InMemoryWebhookDeliveryRepository();
        $cipher = new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString());
        $responseFactory = new ResponseFactory();
        $context = new RequestUserContext(new AuthenticatedUser(7, 'webhooks@example.com', false), 99);
        $baseRequest = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/webhooks/endpoints?organization_id=99')
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        foreach ([
            static fn (): string => CreateWebhookEndpointAction::stringField(['name' => ' '], 'name'),
            static fn (): bool => CreateWebhookEndpointAction::enabledField(['enabled' => 'true']),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid webhook endpoint field to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $invalidEvents = (new CreateWebhookEndpointAction($endpoints, $cipher))(
            $baseRequest->withParsedBody([
                'name' => 'Blank events',
                'endpoint_url' => 'https://hooks.example/blank-events',
                'events' => ['   '],
                'enabled' => true,
            ]),
            $responseFactory->createResponse(),
        );
        $invalidEventsPayload = json_decode((string) $invalidEvents->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(422, $invalidEvents->getStatusCode());
        self::assertSame('invalid_webhook_endpoint', $invalidEventsPayload['code'] ?? null);

        $endpoint = $endpoints->store(new \VertoAD\Domain\Webhooks\WebhookEndpoint(
            id: null,
            endpointId: 'whe_direct',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Direct endpoint',
            endpointUrl: 'https://hooks.example/direct',
            status: 'active',
            events: ['review.approved'],
            encryptedSigningSecret: $cipher->encrypt('whsec_direct'),
            secretPreview: $cipher->preview('whsec_direct'),
            secretRotatedAt: new DateTimeImmutable('2026-06-10T01:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-06-10T01:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-10T01:00:00+00:00'),
        ));
        $update = new UpdateWebhookEndpointAction($endpoints);
        $missingBody = $update(
            $baseRequest->withMethod('PATCH')->withParsedBody(null),
            $responseFactory->createResponse(),
            ['endpoint_id' => $endpoint->endpointId],
        );
        self::assertSame(422, $missingBody->getStatusCode());

        $invalidUpdate = $update(
            $baseRequest->withMethod('PATCH')->withParsedBody(['enabled' => 'yes']),
            $responseFactory->createResponse(),
            ['endpoint_id' => $endpoint->endpointId],
        );
        $invalidUpdatePayload = json_decode((string) $invalidUpdate->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(422, $invalidUpdate->getStatusCode());
        self::assertSame('invalid_webhook_endpoint', $invalidUpdatePayload['code'] ?? null);

        $list = new ListWebhookDeliveriesAction($deliveries);
        $intLimit = $list(
            $baseRequest->withMethod('GET')->withQueryParams(['organization_id' => 99, 'limit' => 5]),
            $responseFactory->createResponse(),
        );
        self::assertSame(200, $intLimit->getStatusCode());

        $invalidLimit = $list(
            $baseRequest->withMethod('GET')->withQueryParams(['organization_id' => 99, 'limit' => ['bad']]),
            $responseFactory->createResponse(),
        );
        self::assertSame(422, $invalidLimit->getStatusCode());
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $secretFactory = $this->sequentialSecrets();
        $container = (new ContainerBuilder())->addDefinitions([
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new class implements FirstPartySessionRepositoryInterface {
                    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
                    {
                        return hash_equals(hash('sha256', 'fixed-token'), $tokenHash)
                            ? new AuthenticatedUser(7, 'webhooks@example.com', false)
                            : null;
                    }

                    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
                    {
                    }

                    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
                    {
                        return false;
                    }
                },
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
            WebhookEndpointRepositoryInterface::class => static fn (): WebhookEndpointRepositoryInterface =>
                new DatabaseWebhookEndpointRepository($connection),
            WebhookDeliveryRepositoryInterface::class => static fn (): WebhookDeliveryRepositoryInterface =>
                new DatabaseWebhookDeliveryRepository($connection),
            WebhookEndpointSecretCipherInterface::class => static fn (): WebhookEndpointSecretCipherInterface =>
                new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString(), $secretFactory),
            WebhookEndpointSecretCipher::class => static fn (): WebhookEndpointSecretCipher =>
                new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString(), $secretFactory),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();

        $permission = static function (string $code) use ($app, $container): RequirePermissionMiddleware {
            return new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                PermissionRequirement::forOrganization($code),
            );
        };

        $app->get('/api/v1/webhooks/endpoints', ListWebhookEndpointsAction::class)
            ->add($permission('webhook.read.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/webhooks/endpoints', CreateWebhookEndpointAction::class)
            ->add($permission('webhook.write.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->patch('/api/v1/webhooks/endpoints/{endpoint_id}', UpdateWebhookEndpointAction::class)
            ->add($permission('webhook.write.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/webhooks/endpoints/{endpoint_id}/rotate-secret', RotateWebhookEndpointSecretAction::class)
            ->add($permission('webhook.secret.rotate.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/webhooks/endpoints/{endpoint_id}/test', TestWebhookEndpointAction::class)
            ->add($permission('webhook.write.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/webhooks/deliveries', ListWebhookDeliveriesAction::class)
            ->add($permission('webhook.delivery.read.own'))
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

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $secrets
     */
    private function assertDoesNotExposeSecretMaterial(array $payload, array $secrets): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('encrypted_signing_secret', $encoded);
        self::assertStringNotContainsString('secret_hash', $encoded);
        self::assertStringNotContainsString('signing_secret', $encoded);
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach ([
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, display_name TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", last_login_at TEXT NULL)',
            'CREATE TABLE organization_members (id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, user_id INTEGER NOT NULL, status TEXT NOT NULL, title TEXT NULL)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, organization_id INTEGER NULL, slug TEXT NOT NULL, name TEXT NOT NULL)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, description TEXT NULL)',
            'CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL)',
            'CREATE TABLE user_roles (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, organization_id INTEGER NULL)',
            'CREATE TABLE webhook_endpoints (id INTEGER PRIMARY KEY AUTOINCREMENT, endpoint_id VARCHAR(160) NOT NULL UNIQUE, organization_id INTEGER NOT NULL, created_by_user_id INTEGER NOT NULL, name VARCHAR(160) NOT NULL, endpoint_url TEXT NOT NULL, status VARCHAR(32) NOT NULL, encrypted_signing_secret TEXT NOT NULL, secret_preview VARCHAR(64) NOT NULL, secret_rotated_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)',
            'CREATE TABLE webhook_endpoint_events (webhook_endpoint_id INTEGER NOT NULL, event_type VARCHAR(120) NOT NULL, PRIMARY KEY (webhook_endpoint_id, event_type))',
            'CREATE TABLE webhook_deliveries (delivery_id VARCHAR(160) PRIMARY KEY, organization_id INTEGER NOT NULL, webhook_endpoint_id INTEGER NOT NULL, endpoint_url TEXT NOT NULL, event_type VARCHAR(120) NOT NULL, payload_json TEXT NOT NULL, status VARCHAR(32) NOT NULL, retry_count INTEGER NOT NULL, next_attempt_at DATETIME NOT NULL, last_attempt_at DATETIME NULL, last_status_code INTEGER NULL, last_error TEXT NULL, signature_header TEXT NULL, created_at DATETIME NOT NULL, delivered_at DATETIME NULL)',
            'CREATE TABLE webhook_delivery_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_id VARCHAR(160) NOT NULL, attempt_number INTEGER NOT NULL, status_code INTEGER NULL, error TEXT NULL, signature_header TEXT NULL, attempted_at DATETIME NOT NULL, duration_ms INTEGER NOT NULL)',
        ] as $sql) {
            $connection->executeStatement($sql);
        }

        $connection->insert('users', [
            'id' => 7,
            'email' => 'webhooks@example.com',
            'password_hash' => 'unused',
            'display_name' => 'Webhook Owner',
            'status' => 'active',
            'last_login_at' => null,
        ]);
        foreach ([99, 100] as $index => $organizationId) {
            $roleId = $index + 1;
            $connection->insert('organization_members', [
                'id' => $roleId,
                'organization_id' => $organizationId,
                'user_id' => 7,
                'status' => 'active',
                'title' => null,
            ]);
            $connection->insert('roles', [
                'id' => $roleId,
                'organization_id' => $organizationId,
                'slug' => 'webhook-manager',
                'name' => 'Webhook Manager',
            ]);
            $connection->insert('user_roles', [
                'user_id' => 7,
                'role_id' => $roleId,
                'organization_id' => $organizationId,
            ]);
        }

        foreach ([
            'webhook.read.own',
            'webhook.write.own',
            'webhook.secret.rotate.own',
            'webhook.delivery.read.own',
        ] as $permissionIndex => $permission) {
            $permissionId = $permissionIndex + 1;
            $connection->insert('permissions', [
                'id' => $permissionId,
                'slug' => $permission,
                'description' => $permission,
            ]);
            $connection->insert('role_permissions', ['role_id' => 1, 'permission_id' => $permissionId]);
            $connection->insert('role_permissions', ['role_id' => 2, 'permission_id' => $permissionId]);
        }

        return $connection;
    }

    private function sequentialSecrets(): callable
    {
        $secrets = ['whsec_route_secret_v1', 'whsec_route_secret_v2', 'whsec_route_secret_v3'];

        return static function () use (&$secrets): string {
            return (string) array_shift($secrets);
        };
    }
}
