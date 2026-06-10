<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;

final class DatabaseWebhookDeliveryRepositoryTest extends TestCase
{
    public function testEndpointScopedDeliveriesSurviveRepositoryInstancesAndSupportRetryLifecycle(): void
    {
        $connection = $this->createConnection();
        $endpointRepository = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $reviewEndpoint = $this->storeEndpoint(
            $endpointRepository,
            endpointId: 'whe_review_delivery',
            endpointUrl: 'https://hooks.example/review',
            events: ['review.approved'],
        );
        $billingEndpoint = $this->storeEndpoint(
            $endpointRepository,
            endpointId: 'whe_billing_delivery',
            endpointUrl: 'https://hooks.example/billing',
            events: ['billing.points_changed'],
        );

        $first = $repository->queueForEndpoint($reviewEndpoint, 'review.approved', ['review_id' => 10]);
        $second = $repository->queueForEndpoint($billingEndpoint, 'billing.points_changed', ['ledger_id' => 20]);

        $fresh = new DatabaseWebhookDeliveryRepository($connection);

        self::assertSame($first->delivery_id, $fresh->find($first->delivery_id)?->delivery_id);
        self::assertSame(99, $fresh->find($first->delivery_id)?->organization_id);
        self::assertSame('whe_review_delivery', $fresh->find($first->delivery_id)?->endpoint_id);
        self::assertSame('https://hooks.example/review', $fresh->find($first->delivery_id)?->endpoint_url);
        self::assertSame('{"review_id":10}', $fresh->find($first->delivery_id)?->payload_json);
        self::assertSame([$first->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $fresh->listForOrganization(99, endpointId: 'whe_review_delivery', status: 'queued', limit: 10),
        ));

        $delivered = new WebhookDelivery(
            delivery_id: $first->delivery_id,
            organization_id: $first->organization_id,
            webhook_endpoint_id: $first->webhook_endpoint_id,
            endpoint_id: $first->endpoint_id,
            endpoint_url: $first->endpoint_url,
            event_type: $first->event_type,
            payload_json: $first->payload_json,
            status: 'delivered',
            retry_count: 1,
            next_attempt_at: $first->next_attempt_at,
            last_attempt_at: new DateTimeImmutable('2026-06-09T02:00:00+00:00'),
            last_status_code: 202,
            last_error: null,
            signature_header: 't=1,v1=' . str_repeat('a', 64),
            created_at: $first->created_at,
            delivered_at: new DateTimeImmutable('2026-06-09T02:00:00+00:00'),
        );
        $fresh->save($delivered);
        $fresh->recordAttempt(
            deliveryId: $first->delivery_id,
            attemptNumber: 1,
            statusCode: 202,
            error: null,
            signatureHeader: $delivered->signature_header,
            attemptedAt: new DateTimeImmutable('2026-06-09T02:00:00+00:00'),
            durationMs: 321,
        );

        $pending = $fresh->pendingRetry(10);
        self::assertSame([$second->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $pending,
        ));

        $stored = (new DatabaseWebhookDeliveryRepository($connection))->find($first->delivery_id);
        self::assertNotNull($stored);
        self::assertSame('delivered', $stored->status);
        self::assertSame(1, $stored->retry_count);
        self::assertSame(202, $stored->last_status_code);
        self::assertNull($stored->last_error);
        self::assertSame($delivered->signature_header, $stored->signature_header);
        $exhausted = new WebhookDelivery(
            delivery_id: $second->delivery_id,
            organization_id: $second->organization_id,
            webhook_endpoint_id: $second->webhook_endpoint_id,
            endpoint_id: $second->endpoint_id,
            endpoint_url: $second->endpoint_url,
            event_type: $second->event_type,
            payload_json: $second->payload_json,
            status: 'exhausted',
            retry_count: 3,
            next_attempt_at: new DateTimeImmutable('2026-06-09T02:05:00+00:00'),
            last_attempt_at: new DateTimeImmutable('2026-06-09T02:05:00+00:00'),
            last_status_code: 503,
            last_error: 'HTTP 503',
            signature_header: 't=1,v1=' . str_repeat('b', 64),
            created_at: $second->created_at,
            delivered_at: null,
        );
        $fresh->save($exhausted);
        self::assertSame([$second->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $fresh->listForOrganization(99, endpointId: 'whe_billing_delivery', status: 'exhausted', limit: 10),
        ));
        self::assertSame([], $fresh->pendingRetry(10, new DateTimeImmutable('2026-06-09T03:00:00+00:00')));
        self::assertSame([], $fresh->listForOrganization(100, endpointId: 'whe_billing_delivery', status: 'exhausted', limit: 10));
        self::assertSame([
            [
                'delivery_id' => $first->delivery_id,
                'attempt_number' => 1,
                'status_code' => 202,
                'error' => null,
                'signature_header' => $delivered->signature_header,
                'duration_ms' => 321,
            ],
        ], array_map(
            static fn (array $attempt): array => [
                'delivery_id' => $attempt['delivery_id'],
                'attempt_number' => $attempt['attempt_number'],
                'status_code' => $attempt['status_code'],
                'error' => $attempt['error'],
                'signature_header' => $attempt['signature_header'],
                'duration_ms' => $attempt['duration_ms'],
            ],
            $fresh->attemptsForDelivery($first->delivery_id),
        ));
        self::assertSame(2, count($fresh->all()));
    }

    public function testPendingRetryRejectsNonPositiveLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry batch size must be positive.');

        (new DatabaseWebhookDeliveryRepository($this->createConnection()))->pendingRetry(0);
    }

    public function testPendingRetryRejectsInvalidRetryCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry cap must be positive.');

        (new DatabaseWebhookDeliveryRepository($this->createConnection()))->pendingRetry(10, maxRetryCount: 0);
    }

    public function testPendingRetryFiltersDeliveriesAtRetryCap(): void
    {
        $connection = $this->createConnection();
        $endpointRepository = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $endpoint = $this->storeEndpoint(
            $endpointRepository,
            endpointId: 'whe_retry_cap',
            endpointUrl: 'https://hooks.example/retry-cap',
            events: ['review.approved'],
        );
        $due = new DateTimeImmutable('2026-06-10T00:00:00+00:00');
        $belowCap = $repository->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_below_cap']);
        $atCap = $repository->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_at_cap']);

        $repository->save($this->deliveryWithRetryState($belowCap, retryCount: 2, nextAttemptAt: $due));
        $repository->save($this->deliveryWithRetryState($atCap, retryCount: 3, nextAttemptAt: $due));
        $repository->save($this->deliveryWithRetryState(
            $repository->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_exhausted']),
            retryCount: 2,
            nextAttemptAt: $due,
            status: 'exhausted',
        ));

        self::assertSame([$belowCap->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $repository->pendingRetry(10, $due),
        ));
    }

    public function testMarkDueRetriesExhaustedTransitionsOnlyDueCappedFailuresAndRejectsInvalidInputs(): void
    {
        $connection = $this->createConnection();
        $endpointRepository = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $endpoint = $this->storeEndpoint(
            $endpointRepository,
            endpointId: 'whe_exhaust_due',
            endpointUrl: 'https://hooks.example/exhaust-due',
            events: ['review.approved'],
        );
        $due = new DateTimeImmutable('2026-06-10T00:00:00+00:00');
        $cappedDue = $repository->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_capped_due']);
        $belowCap = $repository->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_below_cap']);
        $futureCapped = $repository->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_future_capped']);

        $repository->save($this->deliveryWithRetryState($cappedDue, retryCount: 3, nextAttemptAt: $due));
        $repository->save($this->deliveryWithRetryState($belowCap, retryCount: 2, nextAttemptAt: $due));
        $repository->save($this->deliveryWithRetryState(
            $futureCapped,
            retryCount: 3,
            nextAttemptAt: $due->modify('+1 hour'),
        ));

        $exhausted = $repository->markDueRetriesExhausted(10, $due, maxRetryCount: 3);

        self::assertSame([$cappedDue->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $exhausted,
        ));
        self::assertSame('exhausted', $repository->find($cappedDue->delivery_id)?->status);
        self::assertSame(
            $due->modify('-5 minutes')->format('Y-m-d H:i:s'),
            $repository->find($cappedDue->delivery_id)?->next_attempt_at->format('Y-m-d H:i:s'),
        );
        self::assertSame('failed', $repository->find($belowCap->delivery_id)?->status);
        self::assertSame('failed', $repository->find($futureCapped->delivery_id)?->status);

        try {
            $repository->markDueRetriesExhausted(0, $due);
            self::fail('Expected exhausted batch size validation to reject zero.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Webhook retry batch size must be positive.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry cap must be positive.');

        $repository->markDueRetriesExhausted(10, $due, maxRetryCount: 0);
    }

    public function testBoundaryInputsRejectMissingEndpointIdAndInvalidListLimit(): void
    {
        $repository = new DatabaseWebhookDeliveryRepository($this->createConnection());
        $time = new DateTimeImmutable('2026-06-09T01:00:00+00:00');

        self::assertSame([], $repository->listForOrganization(0));

        try {
            $repository->queueForEndpoint(new WebhookEndpoint(
                id: null,
                endpointId: 'whe_missing_internal_id',
                organizationId: 99,
                createdByUserId: 7,
                name: 'Missing internal ID',
                endpointUrl: 'https://hooks.example/missing-id',
                status: 'active',
                events: ['review.approved'],
                encryptedSigningSecret: 'defuse:v1:encrypted',
                secretPreview: 'whsec_...',
                secretRotatedAt: $time,
                createdAt: $time,
                updatedAt: $time,
            ), 'review.approved', ['review_id' => 1]);
            self::fail('Expected missing endpoint internal ID to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Webhook endpoint internal ID is required to queue a delivery.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook delivery list limit must be positive.');

        $repository->listForOrganization(99, limit: 0);
    }

    public function testWebhookMigrationDefinesDurableDeliveryLog(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260609010000_create_webhook_delivery_tables.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table webhook_deliveries',
            'delivery_id varchar(160) not null',
            'organization_id bigint unsigned not null',
            'webhook_endpoint_id bigint unsigned not null',
            'endpoint_url varchar(2048) not null',
            'payload_json json not null',
            'status varchar(32) not null',
            'retry_count int unsigned not null',
            'next_attempt_at datetime(6) not null',
            'last_attempt_at datetime(6) null',
            'last_status_code int unsigned null',
            'last_error varchar(255) null',
            'signature_header varchar(255) null',
            'delivered_at datetime(6) null',
            'idx_webhook_deliveries_retry',
            'constraint chk_webhook_deliveries_status check (status in (\'queued\', \'delivered\', \'failed\', \'exhausted\'))',
            'create table webhook_delivery_attempts',
            'attempt_number int unsigned not null',
            'duration_ms int unsigned not null',
            'unique key uq_webhook_delivery_attempts_delivery_attempt',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    /**
     * @param list<string> $events
     */
    private function storeEndpoint(
        DatabaseWebhookEndpointRepository $repository,
        string $endpointId,
        string $endpointUrl,
        array $events,
    ): WebhookEndpoint {
        $time = new DateTimeImmutable('2026-06-09T01:00:00+00:00');

        return $repository->store(new WebhookEndpoint(
            id: null,
            endpointId: $endpointId,
            organizationId: 99,
            createdByUserId: 7,
            name: $endpointId,
            endpointUrl: $endpointUrl,
            status: 'active',
            events: $events,
            encryptedSigningSecret: 'defuse:v1:encrypted',
            secretPreview: 'whsec_...',
            secretRotatedAt: $time,
            createdAt: $time,
            updatedAt: $time,
        ));
    }

    private function deliveryWithRetryState(
        WebhookDelivery $delivery,
        int $retryCount,
        DateTimeImmutable $nextAttemptAt,
        string $status = 'failed',
    ): WebhookDelivery {
        return new WebhookDelivery(
            delivery_id: $delivery->delivery_id,
            organization_id: $delivery->organization_id,
            webhook_endpoint_id: $delivery->webhook_endpoint_id,
            endpoint_id: $delivery->endpoint_id,
            endpoint_url: $delivery->endpoint_url,
            event_type: $delivery->event_type,
            payload_json: $delivery->payload_json,
            status: $status,
            retry_count: $retryCount,
            next_attempt_at: $nextAttemptAt,
            last_attempt_at: $nextAttemptAt->modify('-5 minutes'),
            last_status_code: 503,
            last_error: 'HTTP 503',
            signature_header: $delivery->signature_header,
            created_at: $delivery->created_at,
            delivered_at: null,
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE webhook_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                endpoint_id VARCHAR(160) NOT NULL UNIQUE,
                organization_id INTEGER NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                name VARCHAR(160) NOT NULL,
                endpoint_url TEXT NOT NULL,
                status VARCHAR(32) NOT NULL,
                encrypted_signing_secret TEXT NOT NULL,
                secret_preview VARCHAR(64) NOT NULL,
                secret_rotated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE webhook_endpoint_events (
                webhook_endpoint_id INTEGER NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                PRIMARY KEY (webhook_endpoint_id, event_type)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE webhook_deliveries (
                delivery_id VARCHAR(160) PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                webhook_endpoint_id INTEGER NOT NULL,
                endpoint_url TEXT NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                payload_json TEXT NOT NULL,
                status VARCHAR(32) NOT NULL,
                retry_count INTEGER NOT NULL,
                next_attempt_at DATETIME NOT NULL,
                last_attempt_at DATETIME NULL,
                last_status_code INTEGER NULL,
                last_error TEXT NULL,
                signature_header TEXT NULL,
                created_at DATETIME NOT NULL,
                delivered_at DATETIME NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE webhook_delivery_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                delivery_id VARCHAR(160) NOT NULL,
                attempt_number INTEGER NOT NULL,
                status_code INTEGER NULL,
                error TEXT NULL,
                signature_header TEXT NULL,
                attempted_at DATETIME NOT NULL,
                duration_ms INTEGER NOT NULL
            )',
        );

        return $connection;
    }
}
