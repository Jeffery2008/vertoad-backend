<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Repository\Webhooks\InMemoryWebhookDeliveryRepository;

final class InMemoryWebhookEventDeliveryRepositoryTest extends TestCase
{
    public function testQueuesStableBusinessEventDeliveryAndReturnsItOnReplay(): void
    {
        $repository = new InMemoryWebhookDeliveryRepository();
        $endpoint = $this->endpoint();
        $event = $this->event('evt_review_memory_1');

        $first = $repository->queueEventForEndpoint($endpoint, $event);
        $replayed = $repository->queueEventForEndpoint($endpoint, $event);

        self::assertSame($first, $replayed);
        self::assertSame(
            'whd_' . hash('sha256', $endpoint->endpointId . '|' . $event->eventId),
            $first->delivery_id,
        );
        self::assertSame($event->payloadJson(), $first->payload_json);
        self::assertSame($event->requestId, $first->request_id);
        self::assertCount(1, $repository->all());
    }

    public function testRejectsInvalidBusinessEventTargets(): void
    {
        $repository = new InMemoryWebhookDeliveryRepository();
        $event = $this->event('evt_review_memory_validation');

        foreach ([
            [$this->endpoint(id: null), $event, 'internal ID is required'],
            [$this->endpoint(), $this->event('evt_review_other_org', organizationId: 100), 'organization does not match'],
            [$this->endpoint(status: 'paused'), $event, 'not active for this event type'],
            [$this->endpoint(events: ['billing.points_changed']), $event, 'not active for this event type'],
        ] as [$endpoint, $candidateEvent, $message]) {
            try {
                $repository->queueEventForEndpoint($endpoint, $candidateEvent);
                self::fail('Expected invalid in-memory business-event target.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testRejectsConflictingReplayWithTheSameEndpointAndEventId(): void
    {
        $repository = new InMemoryWebhookDeliveryRepository();
        $endpoint = $this->endpoint();
        $event = $this->event('evt_review_memory_conflict');
        $repository->queueEventForEndpoint($endpoint, $event);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook delivery ID conflicts with an existing event delivery.');
        $repository->queueEventForEndpoint($endpoint, new WebhookEvent(
            eventId: $event->eventId,
            eventType: $event->eventType,
            organizationId: $event->organizationId,
            data: ['review_id' => 999, 'decision' => 'approved'],
            occurredAt: $event->occurredAt,
            requestId: $event->requestId,
        ));
    }

    public function testLegacyQueueNormalizesScalarRequestIds(): void
    {
        $repository = new InMemoryWebhookDeliveryRepository();
        $endpoint = $this->endpoint();

        $withRequest = $repository->queueForEndpoint(
            $endpoint,
            'review.approved',
            ['review_id' => 1, 'request_id' => ' req-memory-normalized '],
        );
        $withoutRequest = $repository->queueForEndpoint(
            $endpoint,
            'review.approved',
            ['review_id' => 2, 'request_id' => ' '],
        );

        self::assertSame('req-memory-normalized', $withRequest->request_id);
        self::assertNull($withoutRequest->request_id);
    }

    /** @param list<string> $events */
    private function endpoint(
        ?int $id = 1,
        int $organizationId = 99,
        string $status = 'active',
        array $events = ['review.approved'],
    ): WebhookEndpoint {
        $time = new DateTimeImmutable('2026-07-12T00:00:00+00:00');

        return new WebhookEndpoint(
            id: $id,
            endpointId: 'whe_memory_business',
            organizationId: $organizationId,
            createdByUserId: 7,
            name: 'Memory business endpoint',
            endpointUrl: 'https://hooks.example/memory-business',
            status: $status,
            events: $events,
            encryptedSigningSecret: 'defuse:v1:encrypted',
            secretPreview: 'whsec_...',
            secretRotatedAt: $time,
            createdAt: $time,
            updatedAt: $time,
        );
    }

    private function event(string $eventId, int $organizationId = 99): WebhookEvent
    {
        return new WebhookEvent(
            eventId: $eventId,
            eventType: 'review.approved',
            organizationId: $organizationId,
            data: ['review_id' => 1, 'decision' => 'approved'],
            occurredAt: new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
            requestId: 'req-memory-business',
        );
    }
}
