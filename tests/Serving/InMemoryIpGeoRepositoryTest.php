<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\GeoIpLookupTask;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;

final class InMemoryIpGeoRepositoryTest extends TestCase
{
    public function testEnsureQueuedSearchAndLeaseRespectStateAndFilters(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.50', 'Agent A', 'CN', 'serving', $queuedAt, 'req-queue-a');
        $repository->ensureQueued('203.0.113.51', 'Agent B', 'US', 'operations', $queuedAt->modify('+1 minute'), 'req-queue-b');
        $repository->ensureQueued('not-an-ip', 'Agent C', 'CN', 'serving', $queuedAt, 'req-queue-c');

        self::assertCount(2, $repository->rows());

        $searched = $repository->searchLookups([
            'request_id' => 'req-queue-a',
            'source' => 'serving',
            'status' => 'pending',
            'limit' => 5,
        ]);
        self::assertCount(1, $searched);
        self::assertSame('203.0.113.50', $searched[0]->ipAddress);
        self::assertSame('req-queue-a', $searched[0]->requestId);

        $leased = $repository->leasePending(1, new DateTimeImmutable('2026-06-16T00:00:05+00:00'));
        self::assertCount(1, $leased);
        self::assertSame('203.0.113.50', $leased[0]->ipAddress);
        self::assertSame('processing', array_values($repository->rows())[0]['status']);

        try {
            $repository->leasePending(0, new DateTimeImmutable('2026-06-16T00:00:05+00:00'));
            self::fail('Expected non-positive lease limit to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo cron batch size must be positive.', $exception->getMessage());
        }
    }

    public function testMarkResolvedKeepsQueuedMetadataAndMarksResolvedRows(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.52', 'Agent A', 'CN', 'serving', $queuedAt, 'req-queue-resolve');

        $record = new GeoIpRecord(
            ipAddress: '203.0.113.52',
            countryCode: 'CN',
            countryName: 'China',
            regionCode: 'SH',
            regionName: 'Shanghai',
            cityName: 'Shanghai',
            latitude: 31.2304,
            longitude: 121.4737,
            timezone: 'Asia/Shanghai',
            providerId: 'ip-sb',
            resolvedAt: new DateTimeImmutable('2026-06-16T00:01:00+00:00'),
            rawPayloadHash: hash('sha256', '{"country_code":"CN"}'),
            rawPayloadSummary: ['country_code' => 'CN'],
        );

        $repository->markResolved($record);
        $rows = $repository->rows();
        $row = array_values($rows)[0];

        self::assertSame('resolved', $row['status']);
        self::assertSame('ip-sb', $row['provider_id']);
        self::assertSame($record, $row['record']);
        self::assertSame('req-queue-resolve', $row['request_id']);
        self::assertSame(['req-queue-resolve'], $row['request_ids']);
        self::assertSame($record->resolvedAt, $row['resolved_at']);
        self::assertSame($queuedAt, $row['created_at']);
        self::assertSame($record, $repository->findResolved('203.0.113.52'));

        $repository->ensureQueued('203.0.113.52', 'Agent B', 'US', 'serving', new DateTimeImmutable('2026-06-16T00:02:00+00:00'), 'req-queue-resolve-2');
        self::assertSame('resolved', array_values($repository->rows())[0]['status']);
    }

    public function testLeaseTokenRejectsStaleInMemoryWorkerWrites(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.63', 'Agent A', 'CN', 'serving', $queuedAt, 'req-lease-token');
        $leased = $repository->leasePending(1, $queuedAt->modify('+1 minute'))[0];

        $repository->markResolved($this->record('203.0.113.63', $queuedAt->modify('+2 minutes')), 'stale-token');
        $repository->markFailed('203.0.113.63', 'provider-stale', 'stale failure', $queuedAt->modify('+3 minutes'), 2, 60, 'stale-token');
        $repository->markFailed('203.0.113.64', 'provider-missing', 'missing failure', $queuedAt->modify('+3 minutes'), 2, 60, 'missing-token');

        self::assertNull($repository->findResolved('203.0.113.63'));
        self::assertSame('processing', array_values($repository->rows())[0]['status']);
        self::assertSame($leased->leaseToken, array_values($repository->rows())[0]['lease_token']);
        self::assertCount(1, $repository->rows());

        $repository->markResolved($this->record('203.0.113.63', $queuedAt->modify('+4 minutes')), $leased->leaseToken);
        $repository->markFailed('203.0.113.63', 'provider-after-resolve', 'ignored', $queuedAt->modify('+5 minutes'), 2, 60);

        self::assertSame('resolved', array_values($repository->rows())[0]['status']);
        self::assertSame('provider-cn', array_values($repository->rows())[0]['provider_id']);
    }

    public function testMarkFailedTransitionsToDeadAfterMaxAttemptsAndSupportsSearchWindows(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.53', 'Agent A', 'CN', 'serving', $queuedAt, 'req-fail');

        $repository->markFailed('203.0.113.53', 'provider-a', ' first failure ', new DateTimeImmutable('2026-06-16T00:01:00+00:00'), 2, 60);
        $first = array_values($repository->rows())[0];
        self::assertSame('failed', $first['status']);
        self::assertSame(1, $first['attempts']);
        self::assertSame('provider-a', $first['provider_id']);
        self::assertSame('first failure', $first['last_error']);
        self::assertInstanceOf(DateTimeImmutable::class, $first['next_attempt_at']);

        self::assertCount(1, $repository->searchLookups([
            'status' => 'failed',
            'request_id' => 'req-fail',
            'occurred_from' => '2026-06-16T00:00:00+00:00',
            'occurred_to' => '2026-06-16T23:59:59+00:00',
            'limit' => 5,
        ]));

        $leasedFailed = $repository->leasePending(1, new DateTimeImmutable('2026-06-16T00:02:00+00:00'));
        self::assertCount(1, $leasedFailed);
        self::assertSame('processing', $leasedFailed[0]->status);
        self::assertNotNull($leasedFailed[0]->leaseToken);
        self::assertSame('processing', array_values($repository->rows())[0]['status']);

        $repository->markFailed('203.0.113.53', 'provider-b', 'second failure', new DateTimeImmutable('2026-06-16T00:03:00+00:00'), 2, 60);
        $second = array_values($repository->rows())[0];
        self::assertSame('dead', $second['status']);
        self::assertSame(2, $second['attempts']);
        self::assertSame('provider-b', $second['provider_id']);
    }

    public function testSearchLimitFiltersAndMalformedRowsAreHandled(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.54', 'Agent A', 'CN', 'serving', $queuedAt, 'req-a');
        $repository->ensureQueued('203.0.113.55', 'Agent B', 'CN', 'serving', $queuedAt->modify('+1 minute'), 'req-b');

        self::assertCount(1, $repository->searchLookups(['source' => 'serving', 'limit' => 1]));
        self::assertSame([], $repository->searchLookups(['request_id' => 'missing-request']));
        self::assertSame([], $repository->searchLookups(['ip_address' => '203.0.113.99']));
        self::assertSame([], $repository->searchLookups(['occurred_from' => '2026-06-16T00:05:00+00:00']));
        self::assertSame([], $repository->searchLookups(['occurred_to' => '2026-06-15T23:59:00+00:00']));

        $this->replaceRepositoryRows($repository, [
            'bad-row' => [
                'status' => 'pending',
                'ip_address' => 'not-an-ip',
                'user_agent' => null,
                'region_hint' => null,
                'request_id' => null,
                'request_ids' => [],
                'source' => 'serving',
                'attempts' => 0,
                'next_attempt_at' => $queuedAt,
                'created_at' => $queuedAt,
            ],
        ]);

        self::assertSame([], $repository->searchLookups(['limit' => 10]));
    }

    public function testLeaseSkipsNonPendingFutureAndMalformedRows(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $now = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $this->replaceRepositoryRows($repository, [
            'processing-row' => [
                'status' => 'processing',
                'ip_address' => '203.0.113.56',
                'user_agent' => null,
                'region_hint' => null,
                'request_id' => null,
                'request_ids' => [],
                'source' => 'serving',
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
            ],
            'future-row' => [
                'status' => 'pending',
                'ip_address' => '203.0.113.57',
                'user_agent' => null,
                'region_hint' => null,
                'request_id' => null,
                'request_ids' => [],
                'source' => 'serving',
                'attempts' => 0,
                'next_attempt_at' => $now->modify('+1 hour'),
                'created_at' => $now,
            ],
            'malformed-row' => [
                'status' => 'pending',
                'ip_address' => 'not-an-ip',
                'user_agent' => null,
                'region_hint' => null,
                'request_id' => null,
                'request_ids' => [],
                'source' => 'serving',
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
            ],
        ]);

        self::assertSame([], $repository->leasePending(10, $now));
        $rows = $repository->rows();
        self::assertSame('processing', $rows['processing-row']['status']);
        self::assertSame('pending', $rows['future-row']['status']);
        self::assertSame('processing', $rows['malformed-row']['status']);
    }

    public function testTaskNormalizationFallsBackForInvalidRowMetadata(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $now = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $repository->ensureQueued('203.0.113.58', 'Agent A', null, 'serving', $now, 'req-valid');
        $rows = $repository->rows();
        $hash = array_key_first($rows);
        $rows[$hash]['request_ids'] = 'not-a-list';
        $rows[$hash]['source'] = ' ';
        $rows[$hash]['created_at'] = 'not-a-date';
        $this->replaceRepositoryRows($repository, $rows);

        $task = $repository->searchLookups(['limit' => 10])[0];

        self::assertSame('unknown', $task->source);
        self::assertSame([], $task->requestIds);
        self::assertNotSame('not-a-date', $task->createdAt);
    }

    public function testGeoIpRecordAndLookupTaskCanonicalEdgeCases(): void
    {
        $record = new GeoIpRecord(
            ipAddress: '203.0.113.59',
            countryCode: null,
            countryName: null,
            regionCode: null,
            regionName: null,
            cityName: null,
            latitude: null,
            longitude: null,
            timezone: null,
            providerId: 'provider',
            resolvedAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
            rawPayloadHash: hash('sha256', '{}'),
            rawPayloadSummary: [],
        );

        self::assertNull($record->canonicalGeoCode());

        try {
            new GeoIpLookupTask('203.0.113.60', null, null, 0, new DateTimeImmutable('2026-06-16T00:00:00+00:00'), status: 'unknown');
            self::fail('Expected unsupported lookup task status to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo lookup task status is not supported.', $exception->getMessage());
        }

        try {
            new GeoIpLookupTask('203.0.113.62', null, null, 0, new DateTimeImmutable('2026-06-16T00:00:00+00:00'), leaseToken: ' ');
            self::fail('Expected blank lookup task lease token to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('IP geo lookup task lease token must be non-empty when present.', $exception->getMessage());
        }

        $task = new GeoIpLookupTask(
            ipAddress: '203.0.113.61',
            userAgent: 'Agent A',
            regionHint: 'CN',
            attempts: 1,
            createdAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
            requestId: 'req-task',
            requestIds: ['req-task', 'req-extra'],
            source: 'serving',
            status: 'failed',
            providerId: 'provider-a',
            lastError: 'temporary failure',
            nextAttemptAt: new DateTimeImmutable('2026-06-16T00:01:00+00:00'),
            resolvedAt: null,
        );

        self::assertSame([
            'ip_address' => '203.0.113.61',
            'user_agent' => 'Agent A',
            'region_hint' => 'CN',
            'request_id' => 'req-task',
            'request_ids' => ['req-task', 'req-extra'],
            'source' => 'serving',
            'status' => 'failed',
            'attempts' => 1,
            'provider_id' => 'provider-a',
            'last_error' => 'temporary failure',
            'created_at' => '2026-06-16T00:00:00+00:00',
            'next_attempt_at' => '2026-06-16T00:01:00+00:00',
            'resolved_at' => null,
            'lease_token' => null,
        ], $task->toArray());
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private function replaceRepositoryRows(InMemoryIpGeoRepository $repository, array $rows): void
    {
        $property = new \ReflectionProperty(InMemoryIpGeoRepository::class, 'rows');
        $property->setAccessible(true);
        $property->setValue($repository, $rows);
    }

    private function record(string $ipAddress, DateTimeImmutable $resolvedAt): GeoIpRecord
    {
        return new GeoIpRecord(
            ipAddress: $ipAddress,
            countryCode: 'CN',
            countryName: 'China',
            regionCode: 'GD',
            regionName: 'Guangdong',
            cityName: 'Guangzhou',
            latitude: 23.1291,
            longitude: 113.2644,
            timezone: 'Asia/Shanghai',
            providerId: 'provider-cn',
            resolvedAt: $resolvedAt,
            rawPayloadHash: hash('sha256', '{"country_code":"CN","city":"Guangzhou"}'),
            rawPayloadSummary: ['country_code' => 'CN', 'city' => 'Guangzhou'],
        );
    }
}
