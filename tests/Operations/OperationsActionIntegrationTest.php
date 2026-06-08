<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
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
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Service\Webhooks\WebhookSigner;

final class OperationsActionIntegrationTest extends TestCase
{
    public function testSummaryErrorsRawContextConfigAndWebhookRoutesReturnEnvelopes(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $signer = new WebhookSigner('whsec_test_secret');
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $signer);

        $captured = $errors->captureApiError(
            requestId: 'req-route-1',
            severity: 'error',
            message: 'Route captured error',
            context: ['token' => 'raw-token', 'safe' => 'visible'],
            occurredAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
        );
        $created = $configs->createVersion('security.rate_limit', ['limit' => 60], 7);
        $delivery = $deliveryRepository->queue('https://example.test/webhooks', 'operations.error.created', ['error_id' => 'err_1']);
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
            'config_key' => 'webhooks.timeout',
            'value' => ['seconds' => 10],
        ], new RequestUserContext(new AuthenticatedUser(7, 'ops@example.com', false), null));
        $createInvalid = $this->handle($app, 'POST', '/api/v1/operations/config/versions', [
            'config_key' => '../secrets',
            'value' => ['enabled' => true],
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
        self::assertSame('webhooks.timeout', $create['body']['data']['config_key']);
        self::assertSame(422, $createInvalid['status']);
        self::assertSame(200, $rollback['status']);
        self::assertSame(404, $rollbackMissing['status']);
        self::assertSame($delivery->delivery_id, $webhookList['body']['data']['deliveries'][0]['delivery_id']);
        self::assertSame('delivered', $retry['body']['data']['status']);
        self::assertSame(404, $retryMissing['status']);
        self::assertSame('operations.error.raw_context.viewed', $auditRepository->entries[0]->action);
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
