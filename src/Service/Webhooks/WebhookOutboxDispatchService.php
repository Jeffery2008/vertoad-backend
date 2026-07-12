<?php

declare(strict_types=1);

namespace VertoAD\Service\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Throwable;
use VertoAD\Domain\Webhooks\WebhookOutboxEvent;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEventDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookOutboxRepositoryInterface;

final readonly class WebhookOutboxDispatchService
{
    public function __construct(
        private Connection $connection,
        private WebhookOutboxRepositoryInterface $outbox,
        private WebhookEndpointRepositoryInterface $endpoints,
        private WebhookEventDeliveryRepositoryInterface $deliveries,
        private int $leaseSeconds = 60,
        private int $baseBackoffSeconds = 30,
    ) {
        if ($this->leaseSeconds <= 0 || $this->baseBackoffSeconds <= 0) {
            throw new \InvalidArgumentException('Webhook outbox lease and backoff must be positive.');
        }
    }

    /**
     * @return array{claimed:int,dispatched:int,failed:int,queued_deliveries:int,lease_lost:int}
     */
    public function dispatchPending(int $limit): array
    {
        $metrics = [
            'claimed' => 0,
            'dispatched' => 0,
            'failed' => 0,
            'queued_deliveries' => 0,
            'lease_lost' => 0,
        ];

        $events = $this->outbox->claimPending($limit, $this->leaseSeconds);
        $metrics['claimed'] = count($events);
        foreach ($events as $event) {
            try {
                $queued = $this->dispatchOne($event);
                ++$metrics['dispatched'];
                $metrics['queued_deliveries'] += $queued;
            } catch (Throwable $exception) {
                ++$metrics['failed'];
                $released = $this->outbox->releaseAfterFailure(
                    $event->event->eventId,
                    (string) $event->leaseToken,
                    $exception::class . ': ' . $exception->getMessage(),
                    $this->nextAttemptAt($event),
                );
                if (!$released) {
                    ++$metrics['lease_lost'];
                }
            }
        }

        return $metrics;
    }

    private function dispatchOne(WebhookOutboxEvent $outboxEvent): int
    {
        return $this->connection->transactional(function () use ($outboxEvent): int {
            $endpoints = $this->endpoints->listActiveForEvent(
                $outboxEvent->event->organizationId,
                $outboxEvent->event->eventType,
            );
            foreach ($endpoints as $endpoint) {
                $this->deliveries->queueEventForEndpoint($endpoint, $outboxEvent->event);
            }

            $dispatched = $this->outbox->markDispatched(
                $outboxEvent->event->eventId,
                (string) $outboxEvent->leaseToken,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            if (!$dispatched) {
                throw new RuntimeException('Webhook outbox lease was lost before dispatch completed.');
            }

            return count($endpoints);
        });
    }

    private function nextAttemptAt(WebhookOutboxEvent $event): DateTimeImmutable
    {
        $exponent = min(20, max(0, $event->attemptCount - 1));
        $delay = min(86_400, $this->baseBackoffSeconds * (2 ** $exponent));

        return new DateTimeImmutable('+' . $delay . ' seconds', new DateTimeZone('UTC'));
    }
}
