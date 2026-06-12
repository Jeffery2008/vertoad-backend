<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Http\Action\Operations\CreateConfigVersionAction;
use VertoAD\Http\Action\Operations\GetRawOperationErrorContextAction;
use VertoAD\Http\Action\Operations\ListConfigVersionsAction;
use VertoAD\Http\Action\Operations\ListOperationErrorsAction;
use VertoAD\Http\Action\Operations\ListWebhookDeliveriesAction;
use VertoAD\Http\Action\Operations\OperationsSummaryAction;
use VertoAD\Http\Action\Operations\RetryWebhookDeliveryAction;
use VertoAD\Http\Action\Operations\RollbackConfigVersionAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\Operations\InMemoryConfigVersionRepository;
use VertoAD\Repository\Operations\InMemoryOperationErrorLogRepository;
use VertoAD\Repository\Webhooks\InMemoryWebhookDeliveryRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\ConfigVersionService;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\Operations\OperationsSummaryService;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Service\Webhooks\WebhookSigner;
use VertoAD\Tests\Webhooks\InMemoryWebhookEndpointRepository;

final class OperationsActionIntegrationTest extends TestCase
{
    public function testSummaryErrorsRawContextConfigAndWebhookRoutesReturnEnvelopes(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = new class implements WebhookEndpointSecretCipherInterface {
            public function generateSigningSecret(): string
            {
                return 'whsec_operations_generated';
            }

            public function generateSecret(): string
            {
                return $this->generateSigningSecret();
            }

            public function encrypt(string $plaintext): string
            {
                return 'test-encrypted:' . trim($plaintext);
            }

            public function decrypt(string $ciphertext): string
            {
                return str_replace('test-encrypted:', '', $ciphertext);
            }

            public function preview(string $plaintext): string
            {
                return 'whsec_...' . substr(trim($plaintext), -6);
            }
        };
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $endpointSecret = 'whsec_operations_test_secret';
        $endpoint = $endpointRepository->store(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_operations_errors',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Operations error sink',
            endpointUrl: 'https://example.test/webhooks',
            status: 'active',
            events: ['operations.error.created'],
            encryptedSigningSecret: $secretCipher->encrypt($endpointSecret),
            secretPreview: $secretCipher->preview($endpointSecret),
            secretRotatedAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
            createdAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
            updatedAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
        ));
        $deliveries = new WebhookDeliveryJob(
            $deliveryRepository,
            $endpointRepository,
            $secretCipher,
            static fn (): int => 200,
        );

        $captured = $errors->captureApiError(
            requestId: 'req-route-1',
            severity: 'error',
            message: 'Route captured error',
            context: ['token' => 'raw-token', 'safe' => 'visible'],
            occurredAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
        );
        $created = $configs->createVersion('security.rate_limit', ['limit' => 60, 'window_seconds' => 60], 7);
        $delivery = $deliveryRepository->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_1']);
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries);

        $summary = $this->handle($app, 'GET', '/api/v1/operations/summary');
        $errorsList = $this->handle($app, 'GET', '/api/v1/operations/errors');
        $rawAllowed = $this->handle(
            $app,
            'GET',
            '/api/v1/operations/errors/' . $captured['error_id'] . '/raw-context',
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
        $rawDenied = $this->handle($app, 'GET', '/api/v1/operations/errors/' . $captured['error_id'] . '/raw-context');
        $versions = $this->handle($app, 'GET', '/api/v1/operations/config/versions?config_key=security.rate_limit');
        $versionsInvalid = $this->handle($app, 'GET', '/api/v1/operations/config/versions?config_key=../secrets');
        $create = $this->handle($app, 'POST', '/api/v1/operations/config/versions', [
            'config_key' => 'webhook.delivery_policy',
            'value' => [
                'batch_size' => 50,
                'http_timeout_seconds' => 10,
                'max_retry_count' => 3,
                'retry_base_backoff_seconds' => 300,
            ],
        ], new RequestUserContext(new AuthenticatedUser(7, 'ops@example.com', false), null));
        $createInvalid = $this->handle($app, 'POST', '/api/v1/operations/config/versions', [
            'config_key' => 'webhooks.timeout',
            'value' => ['seconds' => 10],
        ]);
        $rollback = $this->handle(
            $app,
            'POST',
            '/api/v1/operations/config/versions/' . $created['version_id'] . '/rollback',
            context: new RequestUserContext(new AuthenticatedUser(11, 'ops@example.com', false), null),
        );
        $rollbackMissing = $this->handle($app, 'POST', '/api/v1/operations/config/versions/missing/rollback');
        $webhookList = $this->handle($app, 'GET', '/api/v1/operations/webhooks/deliveries');
        $retry = $this->handle($app, 'POST', '/api/v1/operations/webhooks/deliveries/' . $delivery->delivery_id . '/retry');
        $retryMissing = $this->handle($app, 'POST', '/api/v1/operations/webhooks/deliveries/missing/retry');

        self::assertSame('unknown', $summary['body']['data']['backup_status']['status']);
        self::assertSame('req-route-1', $errorsList['body']['data']['errors'][0]['request_id']);
        self::assertNull($errorsList['body']['data']['errors'][0]['raw_context']);
        self::assertSame('raw-token', $rawAllowed['body']['data']['raw_context']['token']);
        self::assertTrue($rawAllowed['body']['data']['audit_on_view']);
        self::assertSame(403, $rawDenied['status']);
        self::assertSame('forbidden', $rawDenied['body']['error']['code']);
        self::assertSame('security.rate_limit', $versions['body']['data']['versions'][0]['config_key']);
        self::assertSame(422, $versionsInvalid['status']);
        self::assertSame('invalid_request', $versionsInvalid['body']['error']['code']);
        self::assertSame(201, $create['status']);
        self::assertSame('webhook.delivery_policy', $create['body']['data']['config_key']);
        self::assertSame(422, $createInvalid['status']);
        self::assertSame(200, $rollback['status']);
        self::assertSame(404, $rollbackMissing['status']);
        self::assertSame($delivery->delivery_id, $webhookList['body']['data']['deliveries'][0]['delivery_id']);
        self::assertSame('delivered', $retry['body']['data']['status']);
        self::assertTrue((new WebhookSigner($endpointSecret))->verify(
            (string) $retry['body']['data']['payload_json'],
            (string) $retry['body']['data']['signature_header'],
        ));
        self::assertSame(404, $retryMissing['status']);
        $rawContextAudit = array_values(array_filter(
            $auditRepository->entries,
            static fn ($entry): bool => $entry->action === 'operations.error.raw_context.viewed',
        ));
        self::assertCount(1, $rawContextAudit);
        self::assertSame($captured['error_id'], $rawContextAudit[0]->metadata['error_id'] ?? null);
    }

    private function createApp(
        OperationErrorCaptureService $errors,
        ConfigVersionService $configs,
        InMemoryWebhookDeliveryRepository $deliveryRepository,
        WebhookDeliveryJob $deliveries,
    ): App {
        $container = (new ContainerBuilder())->addDefinitions([
            OperationsSummaryService::class => static fn (): OperationsSummaryService => new OperationsSummaryService(
                backupStatus: ['status' => 'unknown', 'last_backup_at' => null, 'last_successful_backup_id' => null],
                restoreDrillEvidence: ['last_drill_at' => null, 'evidence_url' => null, 'verified_by' => null],
                redisHardeningInventory: [
                    'password_configured' => true,
                    'dangerous_commands_disabled' => ['FLUSHALL'],
                    'key_prefix' => 'vertoad:test:',
                    'prefix_collision_risk' => 'low',
                ],
            ),
            OperationErrorCaptureService::class => static fn (): OperationErrorCaptureService => $errors,
            ConfigVersionService::class => static fn (): ConfigVersionService => $configs,
            \VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface::class => static fn (): InMemoryWebhookDeliveryRepository => $deliveryRepository,
            WebhookDeliveryJob::class => static fn (): WebhookDeliveryJob => $deliveries,
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/operations/summary', OperationsSummaryAction::class);
        $app->get('/api/v1/operations/errors', ListOperationErrorsAction::class);
        $app->get('/api/v1/operations/errors/{error_id}/raw-context', GetRawOperationErrorContextAction::class);
        $app->get('/api/v1/operations/config/versions', ListConfigVersionsAction::class);
        $app->post('/api/v1/operations/config/versions', CreateConfigVersionAction::class);
        $app->post('/api/v1/operations/config/versions/{version_id}/rollback', RollbackConfigVersionAction::class);
        $app->get('/api/v1/operations/webhooks/deliveries', ListWebhookDeliveriesAction::class);
        $app->post('/api/v1/operations/webhooks/deliveries/{delivery_id}/retry', RetryWebhookDeliveryAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>}
     */
    private function handle(App $app, string $method, string $uri, ?array $payload = null, ?RequestUserContext $context = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($context !== null) {
            $request = $request->withAttribute(RequestUserContext::ATTRIBUTE, $context);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }
}
