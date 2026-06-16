<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Repository\IpGeo\RedisIpGeoRepository;

final class RedisIpGeoRepositoryTest extends TestCase
{
    public function testConstructorRejectsInvalidQueueSettings(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();

        foreach (
            [
                ['', 60, 60, 'IP geo Redis prefix is required.'],
                ['vertoad:test:', 0, 60, 'IP geo record TTL must be positive.'],
                ['vertoad:test:', 60, 0, 'IP geo visibility timeout must be positive.'],
            ] as [$prefix, $ttl, $visibilityTimeout, $message]
        ) {
            try {
                new RedisIpGeoRepository($client, $prefix, $ttl, $visibilityTimeout);
                self::fail('Expected invalid Redis IP geo repository settings to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testFactoryRequiresRedisPassword(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIS_PASSWORD is required for Redis connections.');

        RedisIpGeoRepository::fromSettings(['password' => '']);
    }

    public function testFactoryAppliesCustomSettings(): void
    {
        $repository = RedisIpGeoRepository::fromSettings([
            'driver' => 'predis',
            'password' => 'secret',
            'prefix' => 'vertoad:geo:',
            'ip_geo_record_ttl_seconds' => 7200,
            'ip_geo_visibility_timeout_seconds' => 120,
        ]);

        $prefix = new \ReflectionProperty(RedisIpGeoRepository::class, 'prefix');
        $recordTtl = new \ReflectionProperty(RedisIpGeoRepository::class, 'recordTtlSeconds');
        $visibilityTimeout = new \ReflectionProperty(RedisIpGeoRepository::class, 'visibilityTimeoutSeconds');

        self::assertSame('vertoad:geo:', $prefix->getValue($repository));
        self::assertSame(7200, $recordTtl->getValue($repository));
        self::assertSame(120, $visibilityTimeout->getValue($repository));
    }

    public function testQueuesDeduplicatesSearchesLeasesAndResolvesIpGeoTasks(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');

        self::assertNull($repository->findResolved('198.51.100.10'));
        $repository->ensureQueued('not-an-ip', 'Bad browser', 'US', 'serving', $queuedAt, 'req-bad');

        $repository->ensureQueued('198.51.100.10', 'Geo browser', 'US', 'serving', $queuedAt, 'req-one');
        $repository->ensureQueued('198.51.100.10', 'Geo browser updated', 'US', 'serving', $queuedAt->modify('+1 minute'), 'req-two');
        $repository->ensureQueued('198.51.100.11', null, null, 'risk', $queuedAt->modify('+2 minutes'), null);
        $client->zAdd('vertoad:test:ip-geo:lookups', (float) $queuedAt->modify('+3 minutes')->getTimestamp(), 'vertoad:test:ip-geo:task:missing');

        $tasks = $repository->searchLookups(['request_id' => 'req-two', 'limit' => 10]);

        self::assertCount(1, $tasks);
        self::assertSame('198.51.100.10', $tasks[0]->ipAddress);
        self::assertSame('req-one', $tasks[0]->requestId);
        self::assertSame(['req-one', 'req-two'], $tasks[0]->requestIds);
        self::assertSame('serving', $tasks[0]->source);
        self::assertSame('pending', $tasks[0]->status);
        self::assertFalse($client->zContains('vertoad:test:ip-geo:lookups', 'vertoad:test:ip-geo:task:missing'));
        self::assertSame([], $repository->searchLookups(['source' => 'missing']));
        self::assertSame([], $repository->searchLookups(['status' => 'resolved']));
        self::assertSame([], $repository->searchLookups(['occurred_from' => '2026-06-16T00:05:00+00:00']));
        self::assertSame([], $repository->searchLookups(['occurred_to' => '2026-06-15T23:59:00+00:00']));

        $leased = $repository->leasePending(1, $queuedAt->modify('+10 minutes'));

        self::assertCount(1, $leased);
        self::assertSame('198.51.100.10', $leased[0]->ipAddress);
        self::assertTrue($client->zContains('vertoad:test:ip-geo:processing', 'vertoad:test:ip-geo:task:' . GeoIpRecord::hashIp('198.51.100.10')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IP geo cron batch size must be positive.');
        $repository->leasePending(0, $queuedAt);
    }

    public function testFailedTasksRetryUntilDeadAndResolvedRecordsAreCached(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $failedAt = new DateTimeImmutable('2026-06-16T00:01:00+00:00');

        $repository->ensureQueued('198.51.100.20', 'Retry browser', 'CN', 'serving', $queuedAt, 'req-retry');
        $repository->leasePending(1, $queuedAt);
        $repository->markFailed('198.51.100.20', 'provider-a', str_repeat('x', 300), $failedAt, 3, 10);

        $failed = $repository->searchLookups(['ip_address' => '198.51.100.20', 'status' => 'failed', 'limit' => 10]);
        self::assertCount(1, $failed);
        self::assertSame(1, $failed[0]->attempts);
        self::assertSame('provider-a', $failed[0]->providerId);
        self::assertSame(str_repeat('x', 255), $failed[0]->lastError);
        self::assertSame('2026-06-16T00:01:10+00:00', $failed[0]->nextAttemptAt?->format(DATE_ATOM));

        $repository->markFailed('198.51.100.20', 'provider-b', 'still failing', $failedAt->modify('+30 seconds'), 2, 10);

        $dead = $repository->searchLookups(['ip_address' => '198.51.100.20', 'status' => 'dead', 'limit' => 10]);
        self::assertCount(1, $dead);
        self::assertSame(2, $dead[0]->attempts);
        self::assertSame('provider-b', $dead[0]->providerId);

        $record = $this->record('198.51.100.20', new DateTimeImmutable('2026-06-16T00:03:00+00:00'));
        $repository->markResolved($record);
        $resolved = $repository->findResolved('198.51.100.20');

        self::assertNotNull($resolved);
        self::assertSame('CN', $resolved->countryCode);
        self::assertSame('Guangzhou', $resolved->cityName);
        self::assertSame(23.1291, $resolved->latitude);
        self::assertSame(113.2644, $resolved->longitude);

        $resolvedTask = $repository->searchLookups(['ip_address' => '198.51.100.20', 'status' => 'resolved', 'limit' => 10]);
        self::assertCount(1, $resolvedTask);
        self::assertSame('provider-cn', $resolvedTask[0]->providerId);
        self::assertSame('2026-06-16T00:03:00+00:00', $resolvedTask[0]->resolvedAt?->format(DATE_ATOM));

        $repository->ensureQueued('198.51.100.20', 'Ignored browser', 'US', 'serving', $queuedAt->modify('+1 hour'), 'req-after-resolved');
        self::assertSame(['req-retry'], $resolvedTask[0]->requestIds);
    }

    public function testLeaseDropsMissingProcessingMembersAndFindResolvedIgnoresNonRecordPayloads(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $now = new DateTimeImmutable('2026-06-16T00:00:00+00:00');
        $missingTaskKey = 'vertoad:test:ip-geo:task:' . GeoIpRecord::hashIp('198.51.100.30');

        $client->setEx('vertoad:test:ip-geo:record:' . GeoIpRecord::hashIp('198.51.100.30'), '"not-a-record"', 3600);
        $client->zAdd('vertoad:test:ip-geo:processing', (float) $now->modify('-1 minute')->getTimestamp(), $missingTaskKey);

        self::assertNull($repository->findResolved('198.51.100.30'));
        self::assertSame([], $repository->leasePending(5, $now));
        self::assertFalse($client->zContains('vertoad:test:ip-geo:processing', $missingTaskKey));
    }

    public function testSearchLookupsHonorsLimitAndNormalizesNonListRequestIds(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');

        $repository->ensureQueued('198.51.100.40', 'First browser', 'CN', 'serving', $queuedAt, 'req-first');
        $repository->ensureQueued('198.51.100.41', 'Second browser', 'CN', 'serving', $queuedAt->modify('+1 minute'), 'req-second');
        $this->replaceJson($client, 'vertoad:test:ip-geo:task:' . GeoIpRecord::hashIp('198.51.100.41'), ['request_ids' => 'req-second']);

        $tasks = $repository->searchLookups(['limit' => 1]);

        self::assertCount(1, $tasks);
        self::assertSame('198.51.100.41', $tasks[0]->ipAddress);
        self::assertSame([], $tasks[0]->requestIds);
    }

    public function testDeduplicatedQueueSkipsEmptyMissingAndScalarTasksWhenAppendingRequestIds(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $queuedAt = new DateTimeImmutable('2026-06-16T00:00:00+00:00');

        $repository->ensureQueued('198.51.100.50', 'No request id browser', 'CN', 'serving', $queuedAt, null);
        $repository->ensureQueued('198.51.100.50', 'Ignored browser', 'CN', 'serving', $queuedAt->modify('+1 minute'), null);
        $taskKey = 'vertoad:test:ip-geo:task:' . GeoIpRecord::hashIp('198.51.100.50');

        $client->delete($taskKey);
        $client->zAdd('vertoad:test:ip-geo:lookups', (float) $queuedAt->modify('+2 minutes')->getTimestamp(), $taskKey);
        $repository->ensureQueued('198.51.100.50', 'Missing payload browser', 'CN', 'serving', $queuedAt->modify('+2 minutes'), 'req-missing');

        $client->setEx($taskKey, '"scalar-task"', 3600);
        $repository->ensureQueued('198.51.100.50', 'Scalar payload browser', 'CN', 'serving', $queuedAt->modify('+3 minutes'), 'req-scalar');

        self::assertSame('"scalar-task"', $client->get($taskKey));
        self::assertSame([], $repository->searchLookups(['ip_address' => '198.51.100.50', 'limit' => 10]));
        self::assertFalse($client->zContains('vertoad:test:ip-geo:lookups', $taskKey));
    }

    public function testAppendRequestIdToTaskReturnsEarlyForMissingPayload(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $taskKey = 'vertoad:test:ip-geo:task:' . GeoIpRecord::hashIp('198.51.100.70');
        $method = new \ReflectionMethod(RedisIpGeoRepository::class, 'appendRequestIdToTask');
        $method->setAccessible(true);

        $method->invoke($repository, $taskKey, 'req-missing');

        self::assertFalse($client->exists($taskKey));
    }

    public function testFailedTaskWithScalarPayloadStartsFreshAttemptState(): void
    {
        $client = new RedisIpGeoRepositoryRedisClient();
        $repository = new RedisIpGeoRepository($client, 'vertoad:test:', 3600, 300);
        $failedAt = new DateTimeImmutable('2026-06-16T00:01:00+00:00');
        $taskKey = 'vertoad:test:ip-geo:task:' . GeoIpRecord::hashIp('198.51.100.60');
        $client->setEx($taskKey, '"scalar-task"', 3600);

        $repository->markFailed('198.51.100.60', null, ' temporary failure ', $failedAt, 3, 10);

        $payload = $client->get($taskKey);
        self::assertIsString($payload);
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['attempts']);
        self::assertNull($data['provider_id']);
        self::assertSame('temporary failure', $data['last_error']);
        self::assertSame('failed', $data['status']);
    }

    private function replaceJson(RedisIpGeoRepositoryRedisClient $client, string $key, array $values): void
    {
        $payload = $client->get($key);
        self::assertIsString($payload);
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $client->setEx($key, json_encode([...$data, ...$values], JSON_THROW_ON_ERROR), 3600);
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
            rawPayloadHash: hash('sha256', '{"country_code":"CN"}'),
            rawPayloadSummary: ['country_code' => 'CN'],
        );
    }
}

final class RedisIpGeoRepositoryRedisClient implements RedisClientInterface
{
    /** @var array<string,string> */
    private array $values = [];

    /** @var array<string,array<string,float>> */
    private array $zsets = [];

    public function exists(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function delete(string $key): int
    {
        if (!isset($this->values[$key])) {
            return 0;
        }

        unset($this->values[$key]);

        return 1;
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        if (($this->values[$key] ?? null) !== $expectedValue) {
            return false;
        }

        unset($this->values[$key]);

        return true;
    }

    public function expire(string $key, int $seconds): bool
    {
        return isset($this->values[$key]) && $seconds > 0;
    }

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function increment(string $key): int
    {
        $this->values[$key] = (string) (((int) ($this->values[$key] ?? '0')) + 1);

        return (int) $this->values[$key];
    }

    public function setNxEx(string $key, string $value, int $seconds): bool
    {
        if (isset($this->values[$key]) || $seconds <= 0) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function setEx(string $key, string $value, int $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }

        $this->values[$key] = $value;

        return true;
    }

    public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
    {
        $fromScore = $from === '-inf' ? -INF : (float) $from;
        $toScore = $to === '+inf' ? INF : (float) $to;
        $members = [];
        foreach ($this->sortedZset($key) as $member => $score) {
            if ($score >= $fromScore && $score <= $toScore) {
                $members[] = $member;
            }
        }

        return array_slice($members, $offset, $count);
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        $created = !isset($this->zsets[$key][$member]);
        $this->zsets[$key][$member] = $score;

        return $created ? 1 : 0;
    }

    public function zRem(string $key, string $member): int
    {
        if (!isset($this->zsets[$key][$member])) {
            return 0;
        }

        unset($this->zsets[$key][$member]);

        return 1;
    }

    public function eval(string $script, array $keys, array $arguments): array
    {
        unset($script);
        [$pendingKey, $processingKey] = $keys;
        [$now, $limit, $deadline] = $arguments;
        $nowScore = (float) $now;

        foreach ($this->zRangeByScore($processingKey, '-inf', (string) $nowScore, 0, PHP_INT_MAX) as $member) {
            $this->zRem($processingKey, $member);
            $this->zAdd($pendingKey, $nowScore, $member);
        }

        $claimed = [];
        foreach ($this->zRangeByScore($pendingKey, '-inf', (string) $nowScore, 0, (int) $limit) as $member) {
            if ($this->zRem($pendingKey, $member) === 1) {
                $this->zAdd($processingKey, (float) $deadline, $member);
                $claimed[] = $member;
            }
        }

        return $claimed;
    }

    public function zContains(string $key, string $member): bool
    {
        return isset($this->zsets[$key][$member]);
    }

    /**
     * @return array<string,float>
     */
    private function sortedZset(string $key): array
    {
        $members = $this->zsets[$key] ?? [];
        asort($members, SORT_NUMERIC);

        return $members;
    }
}
