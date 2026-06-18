<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DI\ContainerBuilder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Http\Action\Operations\CreateConfigVersionAction;
use VertoAD\Http\Action\Operations\GetOperationRequestCorrelationsAction;
use VertoAD\Http\Action\Operations\GetRawOperationErrorContextAction;
use VertoAD\Http\Action\Operations\LookupOperationIpGeoAction;
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
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Webhooks\InMemoryWebhookDeliveryRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\ConfigVersionService;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\Operations\OperationRequestCorrelationService;
use VertoAD\Service\Operations\OperationsSummaryService;
use VertoAD\Service\Operations\RealTimeGeoLookupInterface;
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
        $ipGeoRepository = new InMemoryIpGeoRepository();
        $ipGeoRepository->ensureQueued(
            '203.0.113.121',
            'Operations summary browser',
            'CN',
            'serving',
            new \DateTimeImmutable('2026-06-08T13:05:00Z'),
            'req-route-ip-geo',
        );
        $app = $this->createApp(
            $errors,
            $configs,
            $deliveryRepository,
            $deliveries,
            audit: $audit,
            ipGeoRepository: $ipGeoRepository,
        );

        $summary = $this->handle($app, 'GET', '/api/v1/operations/summary');
        $errorsList = $this->handle($app, 'GET', '/api/v1/operations/errors');
        $filteredErrorsList = $this->handle($app, 'GET', '/api/v1/operations/errors?request_id=req-route-1');
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
            'config_key' => 'billing.default_revenue_share',
            'value' => ['publisher_percent' => 70],
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
        self::assertArrayHasKey('ip_geo_queue', $summary['body']['data']);
        self::assertSame(1, $summary['body']['data']['ip_geo_queue']['counts']['pending']);
        self::assertSame('2026-06-08T13:05:00+00:00', $summary['body']['data']['ip_geo_queue']['oldest_pending_at']);
        self::assertSame('req-route-1', $errorsList['body']['data']['errors'][0]['request_id']);
        self::assertSame('req-route-1', $filteredErrorsList['body']['data']['errors'][0]['request_id']);
        self::assertNull($errorsList['body']['data']['errors'][0]['raw_context']);
        self::assertSame('raw-token', $rawAllowed['body']['data']['raw_context']['token']);
        self::assertTrue($rawAllowed['body']['data']['audit_on_view']);
        self::assertSame(403, $rawDenied['status']);
        self::assertSame('forbidden', $rawDenied['body']['error']['code']);
        self::assertSame('security.rate_limit', $versions['body']['data']['versions'][0]['config_key']);
        self::assertSame(422, $versionsInvalid['status']);
        self::assertSame('invalid_request', $versionsInvalid['body']['error']['code']);
        self::assertSame(201, $create['status']);
        self::assertSame('billing.default_revenue_share', $create['body']['data']['config_key']);
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

    public function testRequestCorrelationRouteReturnsErrorAuditAndWebhookMatchesByRequestId(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $endpoint = $endpointRepository->store(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_operations_errors',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Operations error sink',
            endpointUrl: 'https://example.test/webhooks',
            status: 'active',
            events: ['operations.error.created'],
            encryptedSigningSecret: $secretCipher->encrypt('whsec_operations_test_secret'),
            secretPreview: $secretCipher->preview('whsec_operations_test_secret'),
            secretRotatedAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
            createdAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
            updatedAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
        ));
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);

        $errors->captureApiError(
            requestId: 'req-correlate-1',
            severity: 'error',
            message: 'Correlation request error',
            context: ['safe' => 'visible'],
            occurredAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
        );
        $audit->record(
            action: 'billing.recharge_key.revealed',
            subjectType: 'recharge_key',
            subjectId: 123,
            actorUserId: 7,
            organizationId: 99,
            metadata: ['request_id' => 'req-correlate-1'],
        );
        $delivery = $deliveryRepository->queueForEndpoint($endpoint, 'operations.error.created', [
            'request_id' => 'req-correlate-1',
            'error_id' => 'err_1',
        ]);
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries, audit: $audit);

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-1');

        self::assertSame(200, $correlations['status']);
        self::assertSame('req-correlate-1', $correlations['body']['data']['request_id']);
        self::assertCount(1, $correlations['body']['data']['operation_errors']);
        self::assertCount(1, $correlations['body']['data']['audit_logs']);
        self::assertCount(1, $correlations['body']['data']['webhook_deliveries']);
        self::assertCount(4, $correlations['body']['data']['timeline']);
        self::assertContains('operation_error', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertContains('audit_log', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertContains('webhook_delivery', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertContains('system_log', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertSame($delivery->delivery_id, $correlations['body']['data']['webhook_deliveries'][0]['delivery_id']);

        $collection = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-1&actor_user_id=7&action=billing.recharge_key.revealed');
        self::assertSame(200, $collection['status']);
        self::assertSame(['filters', 'entries', 'page', 'generated_at'], array_keys($collection['body']['data']));
        self::assertSame('req-correlate-1', $collection['body']['data']['filters']['request_id']);
        self::assertCount(1, $collection['body']['data']['entries']);
        self::assertSame('audit_log', $collection['body']['data']['entries'][0]['entry_type']);
        self::assertSame('req-correlate-1', $collection['body']['data']['entries'][0]['request_id']);
        self::assertArrayNotHasKey('request_id', $collection['body']['data']);
        self::assertArrayNotHasKey('timeline', $collection['body']['data']);
        self::assertArrayNotHasKey('operation_errors', $collection['body']['data']);
        self::assertArrayNotHasKey('audit_logs', $collection['body']['data']);
        self::assertArrayNotHasKey('webhook_deliveries', $collection['body']['data']);
        self::assertArrayNotHasKey('system_logs', $collection['body']['data']);
        self::assertArrayNotHasKey('serving_events', $collection['body']['data']);
        self::assertArrayNotHasKey('risk_decisions', $collection['body']['data']);
        self::assertArrayNotHasKey('ip_geo_lookups', $collection['body']['data']);
        self::assertArrayNotHasKey('counts', $collection['body']['data']);
    }

    public function testRequestCorrelationRouteFiltersOperationAndSystemLogsByIpAddress(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);

        $errors->captureApiError(
            requestId: 'req-correlate-ip',
            severity: 'error',
            message: 'IP correlated error',
            context: [
                'ip_address' => '203.0.113.44',
                'user_agent' => 'Ops Browser',
                'path' => '/api/v1/operations/request-correlations',
                'method' => 'GET',
            ],
            occurredAt: new \DateTimeImmutable('2026-06-08T13:10:00Z'),
        );
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries, audit: $audit);

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-ip?ip_address=203.0.113.44');

        self::assertSame(200, $correlations['status']);
        self::assertCount(1, $correlations['body']['data']['operation_errors']);
        self::assertSame('203.0.113.44', $correlations['body']['data']['operation_errors'][0]['redacted_context']['ip_address']);
        self::assertSame('203.0.113.44', $correlations['body']['data']['timeline'][0]['ip_address']);
        self::assertSame('203.0.113.44', $correlations['body']['data']['timeline'][0]['redacted_context']['ip_address']);
    }

    public function testRequestCorrelationRouteIncludesIpGeoLookupTasksByRequestId(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $ipGeoRepository = new InMemoryIpGeoRepository();
        $ipGeoRepository->ensureQueued(
            '203.0.113.77',
            'Geo test browser',
            'CN',
            'serving',
            new \DateTimeImmutable('2026-06-08T13:02:00Z'),
            'req-correlate-geo',
        );
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries, audit: $audit, ipGeoRepository: $ipGeoRepository);

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-geo');
        $collection = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-geo&ip_address=203.0.113.77&entry_type=ip_geo_lookup');

        self::assertSame(200, $correlations['status']);
        self::assertSame('req-correlate-geo', $correlations['body']['data']['request_id']);
        self::assertCount(1, $correlations['body']['data']['ip_geo_lookups']);
        self::assertSame(1, $correlations['body']['data']['counts']['ip_geo_lookups']);
        self::assertContains('ip_geo_lookup', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertSame('203.0.113.77', $correlations['body']['data']['ip_geo_lookups'][0]['ip_address']);
        self::assertSame('req-correlate-geo', $correlations['body']['data']['ip_geo_lookups'][0]['request_id']);
        self::assertSame(200, $collection['status']);
        self::assertCount(1, $collection['body']['data']['entries']);
        self::assertSame('ip_geo_lookup', $collection['body']['data']['entries'][0]['entry_type']);
        self::assertSame('req-correlate-geo', $collection['body']['data']['entries'][0]['request_id']);
    }

    public function testRequestCorrelationRouteReturnsNormalizedIpGeoLookupContracts(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $ipGeoRepository = new InMemoryIpGeoRepository();
        $ipGeoRepository->ensureQueued(
            '203.0.113.79',
            'Geo contract browser',
            'CN',
            'serving',
            new \DateTimeImmutable('2026-06-08T13:04:00Z'),
            'req-correlate-geo-contract',
        );
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries, audit: $audit, ipGeoRepository: $ipGeoRepository);

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-geo-contract');
        $lookup = $correlations['body']['data']['ip_geo_lookups'][0];

        self::assertSame('req-correlate-geo-contract', $correlations['body']['data']['request_id']);
        self::assertSame(sha1('203.0.113.79|2026-06-08T13:04:00+00:00|req-correlate-geo-contract'), $lookup['lookup_id']);
        self::assertSame(hash('sha256', inet_pton('203.0.113.79')), $lookup['ip_hash']);
        self::assertSame('203.0.113.79', $lookup['ip_address']);
        self::assertSame('serving', $lookup['source']);
        self::assertSame('pending', $lookup['status']);
        self::assertNull($lookup['canonical_geo_code']);
        self::assertSame('2026-06-08T13:04:00+00:00', $lookup['queued_at']);
        self::assertNull($lookup['resolved_at']);
        self::assertSame(0, $lookup['attempts']);
        self::assertNull($lookup['last_error']);
        self::assertSame('2026-06-08T13:04:00+00:00', $lookup['next_attempt_at']);
        self::assertSame('Geo contract browser', $lookup['user_agent']);
        self::assertSame('CN', $lookup['region_hint']);
        self::assertSame(['req-correlate-geo-contract'], $lookup['request_ids']);
    }

    public function testRequestCorrelationRouteIncludesAdminIpGeoLookupsByEndpoint(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $ipGeoRepository = new InMemoryIpGeoRepository();
        $ipGeoRepository->ensureQueued(
            '203.0.113.78',
            null,
            null,
            'operations_realtime_lookup',
            new \DateTimeImmutable('2026-06-08T13:03:00Z'),
            'req-correlate-admin-geo',
        );
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries, audit: $audit, ipGeoRepository: $ipGeoRepository);

        $collection = $this->handle(
            $app,
            'GET',
            '/api/v1/operations/request-correlations?request_id=req-correlate-admin-geo&endpoint=POST:%2Fapi%2Fv1%2Foperations%2Fip-geo%2Flookup&entry_type=ip_geo_lookup',
        );

        self::assertSame(200, $collection['status']);
        self::assertCount(1, $collection['body']['data']['entries']);
        self::assertSame('ip_geo_lookup', $collection['body']['data']['entries'][0]['entry_type']);
        self::assertSame('req-correlate-admin-geo', $collection['body']['data']['entries'][0]['request_id']);
        self::assertSame('/api/v1/operations/ip-geo/lookup', $collection['body']['data']['entries'][0]['endpoint']);
        self::assertSame('POST', $collection['body']['data']['entries'][0]['http_method']);
    }

    public function testRequestCorrelationRouteIncludesServingDecisionAndEventByRequestId(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $decisions = new InMemoryAdDecisionRepository();
        $events = new InMemoryAdEventRepository();
        $decision = new AdDecision(
            decisionId: 'decision-correlation-route',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-correlation-route',
            filled: true,
            reason: null,
            iframeHtml: '<iframe title="Advertisement"></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-correlation-route',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/landing',
            decidedAt: new \DateTimeImmutable('2026-06-08T13:01:00Z'),
            requestId: 'req-correlate-serving',
            ipAddress: '198.51.100.88',
            userAgent: 'Correlation browser',
            geoCode: 'CN-SH',
        );
        $decisions->save($decision);
        $events->recordClick($decision, 'click-correlation-route', new \DateTimeImmutable('2026-06-08T13:01:10Z'), 'req-correlate-serving');
        $app = $this->createApp(
            $errors,
            $configs,
            $deliveryRepository,
            $deliveries,
            audit: $audit,
            servingDecisions: $decisions,
            servingEvents: $events,
        );

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-serving');
        $clicks = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-serving&entry_type=click_event');
        $decisionSubject = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-serving&subject_type=ad_decision&subject_id=decision-correlation-route');
        $eventSubject = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-serving&subject_type=ad_event&subject_id=click:click-correlation-route');

        self::assertSame(200, $correlations['status']);
        self::assertSame('req-correlate-serving', $correlations['body']['data']['request_id']);
        self::assertSame(2, $correlations['body']['data']['counts']['serving_events']);
        self::assertCount(2, $correlations['body']['data']['serving_events']);
        self::assertContains('serving_event', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertContains('click_event', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertContains('ads.serve', array_column($correlations['body']['data']['timeline'], 'action'));
        self::assertContains('ads.click', array_column($correlations['body']['data']['timeline'], 'action'));
        self::assertSame('198.51.100.88', $correlations['body']['data']['timeline'][0]['ip_address']);
        self::assertSame(200, $clicks['status']);
        self::assertCount(1, $clicks['body']['data']['entries']);
        self::assertSame('click_event', $clicks['body']['data']['entries'][0]['entry_type']);
        self::assertSame(200, $decisionSubject['status']);
        self::assertCount(1, $decisionSubject['body']['data']['entries']);
        self::assertSame('serving_event', $decisionSubject['body']['data']['entries'][0]['entry_type']);
        self::assertSame('decision-correlation-route', $decisionSubject['body']['data']['entries'][0]['subject']['subject_id']);
        self::assertSame(200, $eventSubject['status']);
        self::assertCount(1, $eventSubject['body']['data']['entries']);
        self::assertSame('click_event', $eventSubject['body']['data']['entries'][0]['entry_type']);
        self::assertSame('click:click-correlation-route', $eventSubject['body']['data']['entries'][0]['subject']['subject_id']);
    }

    public function testRequestCorrelationRouteIncludesSystemLogsRiskDecisionsAndEndpointFilteredWebhook(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $endpoint = $endpointRepository->store(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_correlate_endpoint',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Correlation endpoint',
            endpointUrl: 'https://hooks.example.test/correlate',
            status: 'active',
            events: ['review.completed'],
            encryptedSigningSecret: $secretCipher->encrypt('whsec_operations_test_secret'),
            secretPreview: $secretCipher->preview('whsec_operations_test_secret'),
            secretRotatedAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
            createdAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
            updatedAt: new \DateTimeImmutable('2026-06-08T13:00:00Z'),
        ));
        $decisions = new InMemoryAdDecisionRepository();
        $events = new InMemoryAdEventRepository();
        $decision = new AdDecision(
            decisionId: 'decision-risk-route',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-risk-route',
            filled: true,
            reason: null,
            iframeHtml: '<iframe title="Advertisement"></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-risk-route',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/risk',
            decidedAt: new \DateTimeImmutable('2026-06-08T13:02:00Z'),
            requestId: 'req-correlate-full',
            ipAddress: '198.51.100.90',
            userAgent: 'Risk browser',
            geoCode: 'CN-SH',
        );
        $decisions->save($decision);
        $events->recordInvalidClick(
            $decision,
            'click-risk-route',
            new \DateTimeImmutable('2026-06-08T13:02:10Z'),
            'repeat_click_window',
            'req-correlate-full',
        );
        $errors->captureApiError(
            requestId: 'req-correlate-full',
            severity: 'warning',
            message: 'Serving geo cache miss',
            context: ['method' => 'POST', 'path' => '/api/v1/ads/track', 'ip_address' => '198.51.100.90'],
            occurredAt: new \DateTimeImmutable('2026-06-08T13:02:20Z'),
        );
        $deliveryRepository->queueForEndpoint($endpoint, 'review.completed', [
            'request_id' => 'req-correlate-full',
            'review_id' => 'review-risk-route',
        ]);
        $app = $this->createApp(
            $errors,
            $configs,
            $deliveryRepository,
            $deliveries,
            audit: $audit,
            servingDecisions: $decisions,
            servingEvents: $events,
        );

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-full');
        $riskOnly = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-full&entry_type=risk_decision&endpoint=/api/v1/ads/click');
        $webhookOnly = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-full&entry_type=webhook_delivery&endpoint=https://hooks.example.test/correlate');
        $systemByIp = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-full&entry_type=system_log&ip_address=198.51.100.90');
        $riskBySubject = $this->handle($app, 'GET', '/api/v1/operations/request-correlations?request_id=req-correlate-full&entry_type=risk_decision&subject_type=click&subject_id=click-risk-route');

        self::assertSame(200, $correlations['status']);
        self::assertSame(1, $correlations['body']['data']['counts']['system_logs']);
        self::assertSame(1, $correlations['body']['data']['counts']['risk_decisions']);
        self::assertCount(1, $correlations['body']['data']['system_logs']);
        self::assertCount(1, $correlations['body']['data']['risk_decisions']);
        self::assertSame('req-correlate-full', $correlations['body']['data']['system_logs'][0]['request_id']);
        self::assertSame('/api/v1/ads/track', $correlations['body']['data']['system_logs'][0]['endpoint']);
        self::assertSame('ads.click.invalid', $correlations['body']['data']['risk_decisions'][0]['action']);
        self::assertSame(['repeat_click_window'], $correlations['body']['data']['risk_decisions'][0]['reason_codes']);
        self::assertContains('system_log', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertContains('risk_decision', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertSame(200, $riskOnly['status']);
        self::assertCount(1, $riskOnly['body']['data']['entries']);
        self::assertSame('risk_decision', $riskOnly['body']['data']['entries'][0]['entry_type']);
        self::assertSame(200, $webhookOnly['status']);
        self::assertCount(1, $webhookOnly['body']['data']['entries']);
        self::assertSame('webhook_delivery', $webhookOnly['body']['data']['entries'][0]['entry_type']);
        self::assertSame(200, $systemByIp['status']);
        self::assertCount(1, $systemByIp['body']['data']['entries']);
        self::assertSame('system_log', $systemByIp['body']['data']['entries'][0]['entry_type']);
        self::assertSame('198.51.100.90', $systemByIp['body']['data']['entries'][0]['ip_address']);
        self::assertSame(200, $riskBySubject['status']);
        self::assertCount(1, $riskBySubject['body']['data']['entries']);
        self::assertSame('risk_decision', $riskBySubject['body']['data']['entries'][0]['entry_type']);
        self::assertSame('click-risk-route', $riskBySubject['body']['data']['entries'][0]['subject']['subject_id']);
    }

    public function testRequestCorrelationRouteIncludesPersistedServingHistoryWhenBufferIsEmpty(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $historyConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $historyConnection->executeStatement(<<<'SQL'
CREATE TABLE ad_serving_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    event_id TEXT NOT NULL,
    decision_id TEXT NOT NULL,
    site_id INTEGER NOT NULL,
    slot_id INTEGER NOT NULL,
    viewer_id TEXT NOT NULL,
    ad_id TEXT NULL,
    campaign_id INTEGER NULL,
    advertiser_organization_id INTEGER NULL,
    publisher_organization_id INTEGER NULL,
    cost_points INTEGER NULL,
    occurred_at TEXT NOT NULL,
    valid INTEGER NOT NULL,
    reason TEXT NULL,
    visible_ratio REAL NULL,
    visible_ms INTEGER NULL,
    request_id TEXT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    geo_code TEXT NULL
)
SQL);
        $historyConnection->insert('ad_serving_events', [
            'event_type' => 'click',
            'event_id' => 'click-history-route',
            'decision_id' => 'decision-history-route',
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-history-route',
            'ad_id' => 'ad-history-route',
            'campaign_id' => 30,
            'advertiser_organization_id' => 40,
            'publisher_organization_id' => 50,
            'cost_points' => 20,
            'occurred_at' => '2026-06-08 13:02:10',
            'valid' => 1,
            'reason' => null,
            'visible_ratio' => null,
            'visible_ms' => null,
            'request_id' => 'req-correlate-history',
            'ip_address' => '198.51.100.99',
            'user_agent' => 'History browser',
            'geo_code' => 'CN-SH',
        ]);
        $app = $this->createApp(
            $errors,
            $configs,
            $deliveryRepository,
            $deliveries,
            audit: $audit,
            servingEvents: new InMemoryAdEventRepository(),
            servingEventHistory: new DatabaseAdEventRepository($historyConnection),
        );

        $correlations = $this->handle($app, 'GET', '/api/v1/operations/request-correlations/req-correlate-history');

        self::assertSame(200, $correlations['status']);
        self::assertSame(1, $correlations['body']['data']['counts']['serving_events']);
        self::assertCount(1, $correlations['body']['data']['serving_events']);
        self::assertSame('event', $correlations['body']['data']['serving_events'][0]['kind']);
        self::assertContains('click_event', array_column($correlations['body']['data']['timeline'], 'entry_type'));
        self::assertSame('ads.click', $correlations['body']['data']['timeline'][0]['action']);
        self::assertSame('198.51.100.99', $correlations['body']['data']['timeline'][0]['ip_address']);
    }

    public function testIpGeoLookupRouteReturnsCanonicalGeoShape(): void
    {
        $auditRepository = new OperationAuditRepository();
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $configRepository = new InMemoryConfigVersionRepository();
        $deliveryRepository = new InMemoryWebhookDeliveryRepository();
        $endpointRepository = new InMemoryWebhookEndpointRepository();
        $secretCipher = $this->secretCipher();
        $audit = new AuditLogService($auditRepository);
        $errors = new OperationErrorCaptureService($errorRepository, $audit);
        $configs = new ConfigVersionService($configRepository, $audit);
        $deliveries = new WebhookDeliveryJob($deliveryRepository, $endpointRepository, $secretCipher, static fn (): int => 200);
        $geo = new class implements RealTimeGeoLookupInterface {
            public ?string $lastIp = null;

            /**
             * @return array<string, mixed>
             */
            public function lookup(string $ipAddress, ?string $requestId = null): array
            {
                $this->lastIp = $ipAddress;

                return [
                    'ip_address' => $ipAddress,
                    'canonical_geo_code' => 'CN-SH',
                    'country_code' => 'CN',
                    'region_code' => 'SH',
                    'region' => 'Shanghai',
                    'city' => 'Shanghai',
                    'latitude' => null,
                    'longitude' => null,
                    'timezone' => 'Asia/Shanghai',
                    'provider_id' => 'fake',
                    'source' => 'fake',
                    'queried_at' => '2026-06-08T13:30:00Z',
                    'persisted_to_canonical_store' => false,
                ];
            }
        };
        $app = $this->createApp($errors, $configs, $deliveryRepository, $deliveries, $geo, $audit);

        $lookup = $this->handle(
            $app,
            'POST',
            '/api/v1/operations/ip-geo/lookup',
            ['ip_address' => '203.0.113.10'],
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame(200, $lookup['status']);
        self::assertSame('203.0.113.10', $lookup['body']['data']['ip_address']);
        self::assertSame('CN', $lookup['body']['data']['country_code']);
        self::assertSame('Shanghai', $lookup['body']['data']['city']);
        self::assertSame('203.0.113.10', $geo->lastIp);
    }

    private function createApp(
        OperationErrorCaptureService $errors,
        ConfigVersionService $configs,
        InMemoryWebhookDeliveryRepository $deliveryRepository,
        WebhookDeliveryJob $deliveries,
        mixed $geoLookup = null,
        ?AuditLogService $audit = null,
        ?IpGeoRepositoryInterface $ipGeoRepository = null,
        ?AdDecisionRepositoryInterface $servingDecisions = null,
        ?AdEventRepositoryInterface $servingEvents = null,
        ?DatabaseAdEventRepository $servingEventHistory = null,
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
                ipGeoRepository: $ipGeoRepository ?? new InMemoryIpGeoRepository(),
            ),
            OperationErrorCaptureService::class => static fn (): OperationErrorCaptureService => $errors,
            ConfigVersionService::class => static fn (): ConfigVersionService => $configs,
            AuditLogService::class => static fn (): AuditLogService => $audit,
            OperationRequestCorrelationService::class => static fn (): OperationRequestCorrelationService =>
                new OperationRequestCorrelationService(
                    $errors,
                    $audit,
                    $deliveryRepository,
                    $ipGeoRepository,
                    $servingDecisions,
                    $servingEvents,
                    $servingEventHistory,
                ),
            \VertoAD\Service\Operations\RealTimeGeoLookupInterface::class => static fn (): mixed =>
                $geoLookup ?? new class implements RealTimeGeoLookupInterface {
                    /**
                     * @return array<string, mixed>
                     */
                    public function lookup(string $ipAddress, ?string $requestId = null): array
                    {
                        return [
                            'ip_address' => $ipAddress,
                            'canonical_geo_code' => null,
                            'country_code' => null,
                            'region_code' => null,
                            'region' => null,
                            'city' => null,
                            'latitude' => null,
                            'longitude' => null,
                            'timezone' => null,
                            'provider_id' => null,
                            'source' => 'stub',
                            'queried_at' => '2026-06-08T13:30:00Z',
                            'persisted_to_canonical_store' => false,
                        ];
                    }
                },
            \VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface::class => static fn (): InMemoryWebhookDeliveryRepository => $deliveryRepository,
            WebhookDeliveryJob::class => static fn (): WebhookDeliveryJob => $deliveries,
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/operations/summary', OperationsSummaryAction::class);
        $app->get('/api/v1/operations/errors', ListOperationErrorsAction::class);
        $app->get('/api/v1/operations/request-correlations', GetOperationRequestCorrelationsAction::class);
        $app->get('/api/v1/operations/request-correlations/{request_id}', GetOperationRequestCorrelationsAction::class);
        $app->get('/api/v1/operations/errors/{error_id}/raw-context', GetRawOperationErrorContextAction::class);
        $app->post('/api/v1/operations/ip-geo/lookup', LookupOperationIpGeoAction::class);
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

    private function secretCipher(): WebhookEndpointSecretCipherInterface
    {
        return new class implements WebhookEndpointSecretCipherInterface {
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
