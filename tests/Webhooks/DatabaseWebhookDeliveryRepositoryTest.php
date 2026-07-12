<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Http\RequestIdContext;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DatabaseWebhookDeliveryRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestIdContext::clear();
    }

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

        $first = $repository->queueForEndpoint($reviewEndpoint, 'review.approved', ['review_id' => 10, 'request_id' => 'req-webhook-ctx']);
        $second = $repository->queueForEndpoint($billingEndpoint, 'billing.points_changed', ['ledger_id' => 20]);

        $fresh = new DatabaseWebhookDeliveryRepository($connection);

        self::assertSame($first->delivery_id, $fresh->find($first->delivery_id)?->delivery_id);
        self::assertSame(99, $fresh->find($first->delivery_id)?->organization_id);
        self::assertSame('whe_review_delivery', $fresh->find($first->delivery_id)?->endpoint_id);
        self::assertSame('https://hooks.example/review', $fresh->find($first->delivery_id)?->endpoint_url);
        self::assertSame('{"review_id":10,"request_id":"req-webhook-ctx"}', $fresh->find($first->delivery_id)?->payload_json);
        self::assertSame('req-webhook-ctx', $fresh->find($first->delivery_id)?->request_id);
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
            request_id: $first->request_id,
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
            request_id: $second->request_id,
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

    public function testQueueForEndpointInheritsCurrentRequestIdWhenPayloadDoesNotProvideOne(): void
    {
        $connection = $this->createConnection();
        $endpointRepository = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $endpoint = $this->storeEndpoint(
            $endpointRepository,
            endpointId: 'whe_inherit_request',
            endpointUrl: 'https://hooks.example/request',
            events: ['review.approved'],
        );
        RequestIdContext::begin((new ServerRequestFactory())
            ->createServerRequest('POST', '/webhooks')
            ->withHeader('X-Request-Id', 'req-webhook-inherit'));

        $delivery = $repository->queueForEndpoint($endpoint, 'review.approved', ['review_id' => 11]);

        self::assertSame('req-webhook-inherit', $delivery->request_id);
        self::assertSame('req-webhook-inherit', $repository->find($delivery->delivery_id)?->request_id);
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

    public function testBusinessEventQueueRejectsWrongOrganizationInactiveSubscriptionAndMissingId(): void
    {
        $connection = $this->createConnection();
        $endpoints = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $endpoint = $this->storeEndpoint(
            $endpoints,
            endpointId: 'whe_business_validation',
            endpointUrl: 'https://hooks.example/business-validation',
            events: ['review.approved'],
        );
        $event = $this->businessEvent('evt_review_validation');
        $missingId = new WebhookEndpoint(
            id: null,
            endpointId: 'whe_missing_business_id',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Missing business ID',
            endpointUrl: 'https://hooks.example/missing-business-id',
            status: 'active',
            events: ['review.approved'],
            encryptedSigningSecret: 'defuse:v1:encrypted',
            secretPreview: 'whsec_...',
            secretRotatedAt: $event->occurredAt,
            createdAt: $event->occurredAt,
            updatedAt: $event->occurredAt,
        );

        foreach ([
            [$endpoint, $this->businessEvent('evt_wrong_org', organizationId: 100), 'organization does not match'],
            [$endpoint->withChanges(status: 'paused'), $event, 'not active for this event type'],
            [$endpoint, $this->businessEvent('evt_wrong_type', eventType: 'billing.points_changed'), 'not active for this event type'],
            [$missingId, $event, 'internal ID is required'],
        ] as [$candidateEndpoint, $candidateEvent, $message]) {
            try {
                $repository->queueEventForEndpoint($candidateEndpoint, $candidateEvent);
                self::fail('Expected invalid business-event delivery target.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testBusinessEventQueueReturnsConcurrentWinnerAndFailsWhenWinnerCannotBeReloaded(): void
    {
        $connection = $this->createConnection(ConcurrentWebhookDeliveryInsertConnection::class);
        $endpoints = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $endpoint = $this->storeEndpoint(
            $endpoints,
            endpointId: 'whe_business_race',
            endpointUrl: 'https://hooks.example/business-race',
            events: ['review.approved'],
        );

        $winner = $repository->queueEventForEndpoint($endpoint, $this->businessEvent('evt_review_race'));

        self::assertSame($winner->delivery_id, $repository->find($winner->delivery_id)?->delivery_id);
        self::assertCount(1, $repository->all());

        $lostConnection = $this->createConnection(LostWebhookDeliveryInsertConnection::class);
        $lostEndpoints = new DatabaseWebhookEndpointRepository($lostConnection);
        $lostRepository = new DatabaseWebhookDeliveryRepository($lostConnection);
        $lostEndpoint = $this->storeEndpoint(
            $lostEndpoints,
            endpointId: 'whe_business_lost_race',
            endpointUrl: 'https://hooks.example/business-lost-race',
            events: ['review.approved'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery idempotency lookup failed.');
        $lostRepository->queueEventForEndpoint($lostEndpoint, $this->businessEvent('evt_review_lost_race'));
    }

    public function testBusinessEventQueueRejectsEveryConflictingDeterministicDeliveryField(): void
    {
        $connection = $this->createConnection();
        $endpoints = new DatabaseWebhookEndpointRepository($connection);
        $repository = new DatabaseWebhookDeliveryRepository($connection);
        $endpoint = $this->storeEndpoint(
            $endpoints,
            endpointId: 'whe_business_conflict',
            endpointUrl: 'https://hooks.example/business-conflict',
            events: ['review.approved'],
        );
        $otherEndpoint = $this->storeEndpoint(
            $endpoints,
            endpointId: 'whe_business_conflict_other',
            endpointUrl: 'https://hooks.example/business-conflict-other',
            events: ['review.approved'],
        );
        $event = $this->businessEvent('evt_review_conflict');
        $original = $repository->queueEventForEndpoint($endpoint, $event);

        foreach ([
            ['organization_id' => 100],
            ['webhook_endpoint_id' => $otherEndpoint->id],
            ['event_type' => 'review.rejected'],
            ['payload_json' => '{"different":true}'],
            ['request_id' => 'req-different'],
        ] as $changes) {
            $repository->save(new WebhookDelivery(
                delivery_id: $original->delivery_id,
                organization_id: $changes['organization_id'] ?? $original->organization_id,
                webhook_endpoint_id: $changes['webhook_endpoint_id'] ?? $original->webhook_endpoint_id,
                endpoint_id: $original->endpoint_id,
                endpoint_url: $original->endpoint_url,
                event_type: $changes['event_type'] ?? $original->event_type,
                payload_json: $changes['payload_json'] ?? $original->payload_json,
                request_id: $changes['request_id'] ?? $original->request_id,
                status: $original->status,
                retry_count: $original->retry_count,
                next_attempt_at: $original->next_attempt_at,
                last_attempt_at: $original->last_attempt_at,
                last_status_code: $original->last_status_code,
                last_error: $original->last_error,
                signature_header: $original->signature_header,
                created_at: $original->created_at,
                delivered_at: $original->delivered_at,
            ));

            try {
                $repository->queueEventForEndpoint($endpoint, $event);
                self::fail('Expected deterministic delivery conflict.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'Webhook delivery ID conflicts with an existing event delivery.',
                    $exception->getMessage(),
                );
            } finally {
                $repository->save($original);
            }
        }
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
            'request_id varchar(160) null',
            'status varchar(32) not null',
            'retry_count int unsigned not null',
            'next_attempt_at datetime(6) not null',
            'last_attempt_at datetime(6) null',
            'last_status_code int unsigned null',
            'last_error varchar(255) null',
            'signature_header varchar(255) null',
            'delivered_at datetime(6) null',
            'idx_webhook_deliveries_retry',
            'idx_webhook_deliveries_request_time',
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
            request_id: $delivery->request_id,
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

    private function businessEvent(
        string $eventId,
        int $organizationId = 99,
        string $eventType = 'review.approved',
    ): WebhookEvent {
        return new WebhookEvent(
            eventId: $eventId,
            eventType: $eventType,
            organizationId: $organizationId,
            data: ['review_id' => 10, 'decision' => 'approved'],
            occurredAt: new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
            requestId: 'req-review-business',
        );
    }

    /** @param class-string<Connection>|null $wrapperClass */
    private function createConnection(?string $wrapperClass = null): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ($wrapperClass !== null) {
            $params['wrapperClass'] = $wrapperClass;
        }
        $connection = DriverManager::getConnection($params);
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
                request_id TEXT NULL,
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

final class ConcurrentWebhookDeliveryInsertConnection extends Connection
{
    private bool $raceNextDeliveryInsert = true;

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'webhook_deliveries' && $this->raceNextDeliveryInsert) {
            $this->raceNextDeliveryInsert = false;
            parent::insert($table, $data, $types);

            throw new SyntheticWebhookDeliveryUniqueConstraintViolationException();
        }

        return parent::insert($table, $data, $types);
    }
}

final class LostWebhookDeliveryInsertConnection extends Connection
{
    private bool $failNextDeliveryInsert = true;

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'webhook_deliveries' && $this->failNextDeliveryInsert) {
            $this->failNextDeliveryInsert = false;

            throw new SyntheticWebhookDeliveryUniqueConstraintViolationException();
        }

        return parent::insert($table, $data, $types);
    }
}

final class SyntheticWebhookDeliveryUniqueConstraintViolationException extends UniqueConstraintViolationException
{
    public function __construct()
    {
        parent::__construct(new SyntheticWebhookDeliveryDriverException(), null);
    }
}

final class SyntheticWebhookDeliveryDriverException extends \Exception implements DriverException
{
    public function __construct()
    {
        parent::__construct('Synthetic webhook delivery unique constraint violation.');
    }

    public function getSQLState(): ?string
    {
        return '23000';
    }
}
