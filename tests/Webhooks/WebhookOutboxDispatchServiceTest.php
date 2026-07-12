<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Domain\Webhooks\WebhookOutboxEvent;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookOutboxRepository;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEventDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookOutboxRepositoryInterface;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipher;
use VertoAD\Service\Webhooks\WebhookOutboxDispatchService;
use VertoAD\Service\Webhooks\WebhookSigner;

final class WebhookOutboxDispatchServiceTest extends TestCase
{
    public function testFansOutOnlyToActiveMatchingSubscriptionsAndIsReplaySafe(): void
    {
        [$connection, $outbox, $endpoints, $deliveries, $dispatcher] = $this->fixture();
        $endpoints->store($this->endpoint('whe_review_active', 99, 'active', ['review.approved']));
        $endpoints->store($this->endpoint('whe_review_paused', 99, 'paused', ['review.approved']));
        $endpoints->store($this->endpoint('whe_billing_active', 99, 'active', ['billing.points_changed']));
        $endpoints->store($this->endpoint('whe_other_org', 100, 'active', ['review.approved']));
        $event = $this->event('evt_review_decision_10');
        $outbox->enqueue($event, 'creative_review', '10');

        $metrics = $dispatcher->dispatchPending(10);

        self::assertSame([
            'claimed' => 1,
            'dispatched' => 1,
            'failed' => 0,
            'queued_deliveries' => 1,
            'lease_lost' => 0,
        ], $metrics);
        self::assertSame('dispatched', $outbox->findByEventId($event->eventId)?->status);
        self::assertCount(1, $deliveries->all());
        $delivery = $deliveries->all()[0];
        self::assertSame('whe_review_active', $delivery->endpoint_id);
        self::assertSame('req-review-10', $delivery->request_id);
        self::assertSame($event->toArray(), json_decode($delivery->payload_json, true, flags: JSON_THROW_ON_ERROR));

        self::assertSame(0, $dispatcher->dispatchPending(10)['claimed']);
        self::assertCount(1, $deliveries->all());

        $sameDelivery = $deliveries->queueEventForEndpoint(
            $endpoints->findForOrganization('whe_review_active', 99) ?? throw new \RuntimeException(),
            $event,
        );
        self::assertSame($delivery->delivery_id, $sameDelivery->delivery_id);
        self::assertCount(1, $deliveries->all());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM webhook_outbox_events'));
    }

    public function testNoSubscriberStillCompletesDurableEventWithoutCreatingDelivery(): void
    {
        [, $outbox, $endpoints, $deliveries, $dispatcher] = $this->fixture();
        $endpoints->store($this->endpoint('whe_billing_only', 99, 'active', ['billing.points_changed']));
        $outbox->enqueue($this->event('evt_review_decision_11'), 'creative_review', '11');

        $metrics = $dispatcher->dispatchPending(10);

        self::assertSame(1, $metrics['dispatched']);
        self::assertSame(0, $metrics['queued_deliveries']);
        self::assertSame([], $deliveries->all());
    }

    public function testFanOutFailureRollsBackDeliveryTransactionAndLeavesEventRetryable(): void
    {
        [$connection, $outbox, $endpoints, , $dispatcher] = $this->fixture();
        $endpoints->store($this->endpoint('whe_review_active', 99, 'active', ['review.approved']));
        $outbox->enqueue($this->event('evt_review_decision_12'), 'creative_review', '12');
        $connection->executeStatement('DROP TABLE webhook_deliveries');

        $metrics = $dispatcher->dispatchPending(10);

        self::assertSame(1, $metrics['claimed']);
        self::assertSame(0, $metrics['dispatched']);
        self::assertSame(1, $metrics['failed']);
        self::assertSame(0, $metrics['lease_lost']);
        $stored = $outbox->findByEventId('evt_review_decision_12');
        self::assertSame('failed', $stored?->status);
        self::assertStringContainsString('webhook_deliveries', (string) $stored?->lastError);
        self::assertNull($stored?->leaseToken);
    }

    public function testReportsLeaseLossWhenFailedFanOutCannotReleaseItsClaim(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $event = $this->claimedEvent(attemptCount: 30);
        $outbox = $this->createStub(WebhookOutboxRepositoryInterface::class);
        $outbox->method('claimPending')->willReturn([$event]);
        $retryAt = null;
        $outbox->method('releaseAfterFailure')->willReturnCallback(
            static function (
                string $eventId,
                string $leaseToken,
                string $error,
                DateTimeImmutable $availableAt,
            ) use (&$retryAt): bool {
                $retryAt = $availableAt;

                return false;
            },
        );
        $endpoints = $this->createStub(WebhookEndpointRepositoryInterface::class);
        $endpoints->method('listActiveForEvent')->willThrowException(new \RuntimeException('endpoint lookup unavailable'));
        $deliveries = $this->createStub(WebhookEventDeliveryRepositoryInterface::class);
        $dispatcher = new WebhookOutboxDispatchService($connection, $outbox, $endpoints, $deliveries, 60, 30);
        $before = new DateTimeImmutable('now');

        $metrics = $dispatcher->dispatchPending(10);

        self::assertSame(1, $metrics['failed']);
        self::assertSame(1, $metrics['lease_lost']);
        self::assertInstanceOf(DateTimeImmutable::class, $retryAt);
        self::assertGreaterThanOrEqual(86_399, $retryAt->getTimestamp() - $before->getTimestamp());
        self::assertLessThanOrEqual(86_401, $retryAt->getTimestamp() - $before->getTimestamp());
    }

    public function testRollsBackFanOutWhenOutboxDispatchTransitionLosesItsLease(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $event = $this->claimedEvent();
        $outbox = $this->createStub(WebhookOutboxRepositoryInterface::class);
        $outbox->method('claimPending')->willReturn([$event]);
        $outbox->method('markDispatched')->willReturn(false);
        $outbox->method('releaseAfterFailure')->willReturn(true);
        $endpoints = $this->createStub(WebhookEndpointRepositoryInterface::class);
        $endpoints->method('listActiveForEvent')->willReturn([]);
        $deliveries = $this->createStub(WebhookEventDeliveryRepositoryInterface::class);
        $dispatcher = new WebhookOutboxDispatchService($connection, $outbox, $endpoints, $deliveries, 60, 1);

        $metrics = $dispatcher->dispatchPending(10);

        self::assertSame([
            'claimed' => 1,
            'dispatched' => 0,
            'failed' => 1,
            'queued_deliveries' => 0,
            'lease_lost' => 0,
        ], $metrics);
    }

    public function testRejectsInvalidLeaseAndBackoffConfiguration(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $outbox = $this->createStub(WebhookOutboxRepositoryInterface::class);
        $endpoints = $this->createStub(WebhookEndpointRepositoryInterface::class);
        $deliveries = $this->createStub(WebhookEventDeliveryRepositoryInterface::class);

        foreach ([[0, 1], [1, 0]] as [$leaseSeconds, $baseBackoffSeconds]) {
            try {
                new WebhookOutboxDispatchService(
                    $connection,
                    $outbox,
                    $endpoints,
                    $deliveries,
                    $leaseSeconds,
                    $baseBackoffSeconds,
                );
                self::fail('Expected invalid outbox dispatcher timing configuration.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Webhook outbox lease and backoff must be positive.', $exception->getMessage());
            }
        }
    }

    public function testRetryCronDispatchesOutboxThenUsesExistingSignatureDeliveryContract(): void
    {
        [$connection, $outbox, $endpoints, $deliveries, $dispatcher] = $this->fixture();
        $cipher = new WebhookEndpointSecretCipher(Key::createNewRandomKey()->saveToAsciiSafeString());
        $secret = 'whsec_business_event_signature';
        $endpoint = $this->endpoint('whe_review_signed', 99, 'active', ['review.approved']);
        $endpoint = $endpoints->store(new WebhookEndpoint(
            id: $endpoint->id,
            endpointId: $endpoint->endpointId,
            organizationId: $endpoint->organizationId,
            createdByUserId: $endpoint->createdByUserId,
            name: $endpoint->name,
            endpointUrl: $endpoint->endpointUrl,
            status: $endpoint->status,
            events: $endpoint->events,
            encryptedSigningSecret: $cipher->encrypt($secret),
            secretPreview: $cipher->preview($secret),
            secretRotatedAt: $endpoint->secretRotatedAt,
            createdAt: $endpoint->createdAt,
            updatedAt: $endpoint->updatedAt,
        ));
        $event = $this->event('evt_review_decision_13');
        $outbox->enqueue($event, 'creative_review', '13');
        $capturedSignature = null;
        $job = new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static function ($delivery, string $signature) use (&$capturedSignature): int {
                $capturedSignature = $signature;

                return 204;
            },
            batchSize: 10,
            maxRetryCount: 3,
            baseBackoffSeconds: 1,
            outboxDispatcher: $dispatcher,
        );

        $result = $job->run();

        self::assertSame(1, $result->metrics['outbox_claimed'] ?? null);
        self::assertSame(1, $result->metrics['outbox_dispatched'] ?? null);
        self::assertSame(1, $result->metrics['queued_deliveries'] ?? null);
        self::assertSame(1, $result->metrics['delivered'] ?? null);
        self::assertCount(1, $deliveries->all());
        self::assertSame('delivered', $deliveries->all()[0]->status);
        self::assertTrue((new WebhookSigner($secret))->verify($event->payloadJson(), (string) $capturedSignature));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM webhook_delivery_attempts'));
        self::assertSame($endpoint->id, $deliveries->all()[0]->webhook_endpoint_id);
    }

    /**
     * @return array{Connection,DatabaseWebhookOutboxRepository,DatabaseWebhookEndpointRepository,DatabaseWebhookDeliveryRepository,WebhookOutboxDispatchService}
     */
    private function fixture(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WebhookOutboxTestSchema::create($connection);
        $outbox = new DatabaseWebhookOutboxRepository($connection);
        $endpoints = new DatabaseWebhookEndpointRepository($connection);
        $deliveries = new DatabaseWebhookDeliveryRepository($connection);

        return [
            $connection,
            $outbox,
            $endpoints,
            $deliveries,
            new WebhookOutboxDispatchService($connection, $outbox, $endpoints, $deliveries, 60, 1),
        ];
    }

    /** @param list<string> $events */
    private function endpoint(string $endpointId, int $organizationId, string $status, array $events): WebhookEndpoint
    {
        $now = new DateTimeImmutable('2026-07-12T00:00:00+00:00');

        return new WebhookEndpoint(
            id: null,
            endpointId: $endpointId,
            organizationId: $organizationId,
            createdByUserId: 7,
            name: $endpointId,
            endpointUrl: 'https://hooks.example/' . $endpointId,
            status: $status,
            events: $events,
            encryptedSigningSecret: 'encrypted-test-secret',
            secretPreview: 'whsec_...test',
            secretRotatedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function event(string $eventId): WebhookEvent
    {
        return new WebhookEvent(
            eventId: $eventId,
            eventType: 'review.approved',
            organizationId: 99,
            data: ['review_id' => (int) substr($eventId, strrpos($eventId, '_') + 1), 'decision' => 'approved'],
            occurredAt: new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
            requestId: 'req-review-' . substr($eventId, strrpos($eventId, '_') + 1),
        );
    }

    private function claimedEvent(int $attemptCount = 1): WebhookOutboxEvent
    {
        $time = new DateTimeImmutable('2026-07-12T00:00:00+00:00');

        return new WebhookOutboxEvent(
            id: 1,
            event: $this->event('evt_review_decision_99'),
            aggregateType: 'creative_review',
            aggregateId: '99',
            status: 'processing',
            attemptCount: $attemptCount,
            availableAt: $time,
            leaseToken: 'whlease_test',
            leaseExpiresAt: $time->modify('+60 seconds'),
            lastError: null,
            createdAt: $time,
            dispatchedAt: null,
        );
    }
}
