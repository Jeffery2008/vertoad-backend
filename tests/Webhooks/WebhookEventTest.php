<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Domain\Webhooks\WebhookEventType;
use VertoAD\Domain\Webhooks\WebhookOutboxEvent;

final class WebhookEventTest extends TestCase
{
    public function testSerializesStableVersionedPayloadEnvelope(): void
    {
        $event = new WebhookEvent(
            eventId: 'evt_review_decision_10',
            eventType: WebhookEventType::REVIEW_APPROVED,
            organizationId: 99,
            data: ['review_id' => 10, 'decision' => 'approved'],
            occurredAt: new DateTimeImmutable('2026-07-12T01:02:03.123456+08:00'),
            requestId: 'req-webhook-1',
        );

        self::assertSame([
            'id' => 'evt_review_decision_10',
            'type' => 'review.approved',
            'api_version' => '2026-07-12',
            'created_at' => '2026-07-11T17:02:03.123456Z',
            'organization_id' => 99,
            'request_id' => 'req-webhook-1',
            'data' => ['review_id' => 10, 'decision' => 'approved'],
        ], json_decode($event->payloadJson(), true, flags: JSON_THROW_ON_ERROR));

        self::assertSame([
            'review.approved',
            'review.rejected',
            'billing.points_changed',
            'campaign.status_changed',
            'conversion.received',
            'withdrawal.status_changed',
            'api_client.created',
            'api_client.secret_rotated',
            'webhook.test',
        ], WebhookEventType::subscribable());
    }

    public function testPreservesPersistedApiVersionAndKeepsTheEventTypeCatalogNonInstantiable(): void
    {
        $event = new WebhookEvent(
            eventId: 'evt_review_decision_legacy',
            eventType: WebhookEventType::REVIEW_APPROVED,
            organizationId: 99,
            data: ['review_id' => 10],
            occurredAt: new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
            requestId: null,
            apiVersion: '2026-06-01',
        );

        self::assertSame('2026-06-01', $event->toArray()['api_version']);

        $catalog = new \ReflectionClass(WebhookEventType::class);
        self::assertFalse($catalog->isInstantiable());
        $constructor = $catalog->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($catalog->newInstanceWithoutConstructor());
    }

    #[DataProvider('invalidEventProvider')]
    public function testRejectsInvalidEventIdentity(
        string $eventId,
        string $eventType,
        int $organizationId,
        ?string $requestId,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new WebhookEvent(
            eventId: $eventId,
            eventType: $eventType,
            organizationId: $organizationId,
            data: [],
            occurredAt: new DateTimeImmutable(),
            requestId: $requestId,
        );
    }

    /** @return iterable<string, array{string,string,int,?string,string}> */
    public static function invalidEventProvider(): iterable
    {
        yield 'blank event id' => ['', 'review.approved', 1, null, 'Webhook event ID'];
        yield 'long event id' => [str_repeat('e', 161), 'review.approved', 1, null, 'Webhook event ID'];
        yield 'unknown event' => ['evt_1', 'review.completed', 1, null, 'Webhook event type is not supported.'];
        yield 'invalid organization' => ['evt_1', 'review.approved', 0, null, 'organization ID must be positive'];
        yield 'blank request id' => ['evt_1', 'review.approved', 1, '', 'request ID'];
        yield 'long request id' => ['evt_1', 'review.approved', 1, str_repeat('r', 161), 'request ID'];
    }

    public function testRejectsInvalidApiVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API version must use YYYY-MM-DD');

        new WebhookEvent(
            eventId: 'evt_1',
            eventType: WebhookEventType::REVIEW_APPROVED,
            organizationId: 1,
            data: ['review_id' => 1],
            occurredAt: new DateTimeImmutable(),
            requestId: null,
            apiVersion: 'v1',
        );
    }

    #[DataProvider('invalidOutboxStateProvider')]
    public function testRejectsInvalidOutboxState(array $changes, string $message): void
    {
        $time = new DateTimeImmutable('2026-07-12T00:00:00+00:00');
        $arguments = array_replace([
            'id' => 1,
            'event' => new WebhookEvent(
                eventId: 'evt_ledger_entry_1',
                eventType: WebhookEventType::BILLING_POINTS_CHANGED,
                organizationId: 99,
                data: ['ledger_entry_id' => 1],
                occurredAt: $time,
                requestId: null,
            ),
            'aggregateType' => 'ledger_entry',
            'aggregateId' => '1',
            'status' => 'pending',
            'attemptCount' => 0,
            'availableAt' => $time,
            'leaseToken' => null,
            'leaseExpiresAt' => null,
            'lastError' => null,
            'createdAt' => $time,
            'dispatchedAt' => null,
        ], $changes);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new WebhookOutboxEvent(...$arguments);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOutboxStateProvider(): iterable
    {
        yield 'invalid id' => [['id' => 0], 'internal ID must be positive'];
        yield 'blank aggregate type' => [['aggregateType' => ' '], 'aggregate type'];
        yield 'long aggregate type' => [['aggregateType' => str_repeat('a', 81)], 'aggregate type'];
        yield 'blank aggregate id' => [['aggregateId' => ' '], 'aggregate ID'];
        yield 'long aggregate id' => [['aggregateId' => str_repeat('a', 161)], 'aggregate ID'];
        yield 'unknown status' => [['status' => 'unknown'], 'status is not supported'];
        yield 'negative attempts' => [['attemptCount' => -1], 'attempt count cannot be negative'];
        yield 'processing without lease' => [['status' => 'processing'], 'require an active lease'];
        yield 'processing without expiry' => [[
            'status' => 'processing',
            'leaseToken' => 'whlease_missing_expiry',
        ], 'require an active lease'];
    }
}
