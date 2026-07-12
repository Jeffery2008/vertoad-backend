<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookEvent;
use VertoAD\Repository\Webhooks\DatabaseWebhookOutboxRepository;

final class WebhookOutboxRepositoryTest extends TestCase
{
    public function testEnqueueIsIdempotentAndRejectsConflictingEventReuse(): void
    {
        $repository = $this->repository();
        $event = $this->event('evt_ledger_entry_10');

        $first = $repository->enqueue($event, 'ledger_entry', '10');
        $replayed = $repository->enqueue($event, 'ledger_entry', '10');

        self::assertSame($first->id, $replayed->id);
        self::assertCount(1, $repository->all());

        foreach ([
            ['eventType' => 'review.approved'],
            ['apiVersion' => '2026-06-01'],
            ['organizationId' => 100],
            ['data' => ['ledger_entry_id' => 11]],
            ['occurredAt' => $event->occurredAt->modify('+1 second')],
            ['requestId' => 'req-outbox-conflict'],
            ['aggregateType' => 'billing_entry'],
            ['aggregateId' => '11'],
        ] as $changes) {
            try {
                $repository->enqueue(new WebhookEvent(
                    eventId: $event->eventId,
                    eventType: $changes['eventType'] ?? $event->eventType,
                    organizationId: $changes['organizationId'] ?? $event->organizationId,
                    data: $changes['data'] ?? $event->data,
                    occurredAt: $changes['occurredAt'] ?? $event->occurredAt,
                    requestId: $changes['requestId'] ?? $event->requestId,
                    apiVersion: $changes['apiVersion'] ?? $event->apiVersion,
                ), $changes['aggregateType'] ?? 'ledger_entry', $changes['aggregateId'] ?? '10');
                self::fail('Expected conflicting event ID reuse to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('conflicts with an existing event', $exception->getMessage());
            }
        }

        self::assertCount(1, $repository->all());
    }

    public function testClaimFailureBackoffAndDispatchUseLeaseOwnership(): void
    {
        $repository = $this->repository();
        $repository->enqueue($this->event('evt_ledger_entry_20'), 'ledger_entry', '20');
        $now = new DateTimeImmutable('2100-07-12T00:00:00+00:00');

        $claimed = $repository->claimPending(10, 60, $now);
        self::assertCount(1, $claimed);
        self::assertSame('processing', $claimed[0]->status);
        self::assertSame(1, $claimed[0]->attemptCount);
        self::assertNotNull($claimed[0]->leaseToken);
        self::assertSame([], $repository->claimPending(10, 60, $now->modify('+30 seconds')));
        self::assertFalse($repository->markDispatched(
            $claimed[0]->event->eventId,
            'wrong-lease',
            $now,
        ));

        self::assertTrue($repository->releaseAfterFailure(
            $claimed[0]->event->eventId,
            (string) $claimed[0]->leaseToken,
            'temporary fan-out failure',
            $now->modify('+30 seconds'),
        ));
        self::assertSame([], $repository->claimPending(10, 60, $now->modify('+29 seconds')));

        $retried = $repository->claimPending(10, 60, $now->modify('+30 seconds'));
        self::assertCount(1, $retried);
        self::assertSame(2, $retried[0]->attemptCount);
        self::assertTrue($repository->markDispatched(
            $retried[0]->event->eventId,
            (string) $retried[0]->leaseToken,
            $now->modify('+31 seconds'),
        ));

        $stored = $repository->findByEventId('evt_ledger_entry_20');
        self::assertSame('dispatched', $stored?->status);
        self::assertNull($stored?->lastError);
        self::assertNotNull($stored?->dispatchedAt);
    }

    public function testExpiredProcessingLeaseCanBeReclaimedAfterWorkerCrash(): void
    {
        $repository = $this->repository();
        $repository->enqueue($this->event('evt_ledger_entry_30'), 'ledger_entry', '30');
        $now = new DateTimeImmutable('2100-07-12T00:00:00+00:00');
        $first = $repository->claimPending(1, 10, $now)[0];

        self::assertSame([], $repository->claimPending(1, 10, $now->modify('+9 seconds')));
        $reclaimed = $repository->claimPending(1, 10, $now->modify('+10 seconds'));

        self::assertCount(1, $reclaimed);
        self::assertSame(2, $reclaimed[0]->attemptCount);
        self::assertNotSame($first->leaseToken, $reclaimed[0]->leaseToken);
        self::assertFalse($repository->releaseAfterFailure(
            $first->event->eventId,
            (string) $first->leaseToken,
            'stale worker',
            $now,
        ));
    }

    public function testClaimInputValidationAndBlankLookup(): void
    {
        $repository = $this->repository();
        self::assertNull($repository->findByEventId(' '));

        try {
            $repository->claimPending(0, 60);
            self::fail('Expected invalid claim limit.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Webhook outbox claim limit must be positive.', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook outbox lease duration must be positive.');
        $repository->claimPending(1, 0);
    }

    public function testEnqueueRejectsInvalidAggregateBeforePersistence(): void
    {
        $repository = $this->repository();

        foreach ([
            [' ', '40', 'aggregate type'],
            [str_repeat('a', 81), '40', 'aggregate type'],
            ['ledger_entry', ' ', 'aggregate ID'],
            ['ledger_entry', str_repeat('1', 161), 'aggregate ID'],
        ] as $index => [$aggregateType, $aggregateId, $message]) {
            try {
                $repository->enqueue(
                    $this->event('evt_ledger_entry_' . (40 + $index)),
                    $aggregateType,
                    $aggregateId,
                );
                self::fail('Expected invalid aggregate identity.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }

        self::assertSame([], $repository->all());
    }

    public function testUniqueConstraintRaceWithoutReloadableWinnerFailsClosed(): void
    {
        $repository = $this->repository(LostWebhookOutboxInsertConnection::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook outbox idempotency lookup failed.');
        $repository->enqueue($this->event('evt_ledger_entry_50'), 'ledger_entry', '50');
    }

    public function testMysqlClaimsUseSkipLocked(): void
    {
        $connection = $this->connection(MySqlWebhookOutboxClaimSpyConnection::class);
        self::assertInstanceOf(MySqlWebhookOutboxClaimSpyConnection::class, $connection);
        $repository = new DatabaseWebhookOutboxRepository($connection);

        self::assertSame([], $repository->claimPending(
            10,
            60,
            new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
        ));
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $connection->lastFetchAllSql);
    }

    public function testMalformedOutboxDataFailsClosedInsteadOfDispatchingAnInvalidEnvelope(): void
    {
        $connection = $this->connection();
        $repository = new DatabaseWebhookOutboxRepository($connection);
        $repository->enqueue($this->event('evt_ledger_entry_60'), 'ledger_entry', '60');
        $connection->update(
            'webhook_outbox_events',
            ['data_json' => 'null'],
            ['event_id' => 'evt_ledger_entry_60'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook outbox data must decode to an object.');
        $repository->findByEventId('evt_ledger_entry_60');
    }

    /** @param class-string<Connection>|null $wrapperClass */
    private function repository(?string $wrapperClass = null): DatabaseWebhookOutboxRepository
    {
        return new DatabaseWebhookOutboxRepository($this->connection($wrapperClass));
    }

    /** @param class-string<Connection>|null $wrapperClass */
    private function connection(?string $wrapperClass = null): Connection
    {
        $params = ['driver' => 'pdo_sqlite', 'memory' => true];
        if ($wrapperClass !== null) {
            $params['wrapperClass'] = $wrapperClass;
        }
        $connection = DriverManager::getConnection($params);
        WebhookOutboxTestSchema::create($connection);

        return $connection;
    }

    private function event(string $eventId): WebhookEvent
    {
        return new WebhookEvent(
            eventId: $eventId,
            eventType: 'billing.points_changed',
            organizationId: 99,
            data: ['ledger_entry_id' => (int) substr($eventId, strrpos($eventId, '_') + 1)],
            occurredAt: new DateTimeImmutable('2026-07-12T00:00:00+00:00'),
            requestId: 'req-outbox-test',
        );
    }
}

final class LostWebhookOutboxInsertConnection extends Connection
{
    private bool $failNextOutboxInsert = true;

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'webhook_outbox_events' && $this->failNextOutboxInsert) {
            $this->failNextOutboxInsert = false;

            throw new SyntheticWebhookOutboxUniqueConstraintViolationException();
        }

        return parent::insert($table, $data, $types);
    }
}

final class MySqlWebhookOutboxClaimSpyConnection extends Connection
{
    public string $lastFetchAllSql = '';

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new MySQL80Platform();
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->lastFetchAllSql = $query;

        return [];
    }
}

final class SyntheticWebhookOutboxUniqueConstraintViolationException extends UniqueConstraintViolationException
{
    public function __construct()
    {
        parent::__construct(new SyntheticWebhookOutboxDriverException(), null);
    }
}

final class SyntheticWebhookOutboxDriverException extends \Exception implements DriverException
{
    public function __construct()
    {
        parent::__construct('Synthetic webhook outbox unique constraint violation.');
    }

    public function getSQLState(): ?string
    {
        return '23000';
    }
}
