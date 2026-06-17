<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Repository\IpGeo\DatabaseIpGeoRepository;

final class DatabaseIpGeoRepositoryTest extends TestCase
{
    public function testQueuesDeduplicatesRequestIdsAndPersistsAcrossRepositoryInstances(): void
    {
        $connection = $this->createConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection);

        $repository->ensureQueued('203.0.113.80', 'Browser A', 'CN', 'serving', $queuedAt, 'req-one');
        $repository->ensureQueued('203.0.113.80', 'Browser B', 'CN-SH', 'risk', $queuedAt->modify('+1 minute'), 'req-two');
        $repository->ensureQueued('not-an-ip', 'Bad browser', 'CN', 'serving', $queuedAt, 'req-bad');

        $freshRepository = new DatabaseIpGeoRepository($connection);
        $matches = $freshRepository->searchLookups([
            'request_id' => 'req-two',
            'ip_address' => '203.0.113.80',
            'limit' => 10,
        ]);

        self::assertCount(1, $matches);
        self::assertSame('203.0.113.80', $matches[0]->ipAddress);
        self::assertSame('req-one', $matches[0]->requestId);
        self::assertSame(['req-one', 'req-two'], $matches[0]->requestIds);
        self::assertSame('risk', $matches[0]->source);
        self::assertSame('pending', $matches[0]->status);
        self::assertSame('Browser B', $matches[0]->userAgent);
        self::assertSame('CN-SH', $matches[0]->regionHint);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_lookup_tasks'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_lookup_request_ids'));
    }

    public function testFindResolvedIgnoresInvalidIpAddress(): void
    {
        self::assertNull((new DatabaseIpGeoRepository($this->createConnection()))->findResolved('not-an-ip'));
    }

    public function testConcurrentQueueInsertMergesRequestIdsAfterDuplicateKeyRace(): void
    {
        $connection = $this->createRaceConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection);

        $connection->competingRequestId = 'req-race-first';
        $repository->ensureQueued('203.0.113.86', 'Browser second', 'CN-SH', 'serving', $queuedAt, 'req-race-second');

        $matches = $repository->searchLookups(['request_id' => 'req-race-second', 'limit' => 10]);
        $firstRequestMatches = $repository->searchLookups(['request_id' => 'req-race-first', 'limit' => 10]);

        self::assertCount(1, $matches);
        self::assertCount(1, $firstRequestMatches);
        self::assertSame('req-race-first', $matches[0]->requestId);
        self::assertSame(['req-race-first', 'req-race-second'], $matches[0]->requestIds);
        self::assertSame('Browser second', $matches[0]->userAgent);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_lookup_tasks'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_lookup_request_ids'));
    }

    public function testConcurrentQueueInsertRethrowsWhenWinningRowCannotBeRead(): void
    {
        $repository = new DatabaseIpGeoRepository($this->createLostRaceConnection());

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->ensureQueued(
            '203.0.113.92',
            'Lost race browser',
            'CN',
            'serving',
            new DateTimeImmutable('2026-06-18T00:00:00+00:00'),
            'req-lost-race',
        );
    }

    public function testEnsureQueuedDuringProcessingMergesRequestIdsWithoutReplacingLease(): void
    {
        $connection = $this->createConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection, 604800, 60);
        $repository->ensureQueued('203.0.113.88', 'Browser first', 'CN', 'serving', $queuedAt, 'req-processing-first');
        $leased = $repository->leasePending(1, $queuedAt->modify('+1 minute'))[0];

        $repository->ensureQueued('203.0.113.88', 'Browser second', 'CN-SH', 'risk', $queuedAt->modify('+90 seconds'), 'req-processing-second');

        $matches = $repository->searchLookups(['request_id' => 'req-processing-second', 'limit' => 10]);
        self::assertCount(1, $matches);
        self::assertSame('processing', $matches[0]->status);
        self::assertSame($leased->leaseToken, $matches[0]->leaseToken);
        self::assertSame(['req-processing-first', 'req-processing-second'], $matches[0]->requestIds);
        self::assertSame('Browser second', $matches[0]->userAgent);
        self::assertSame('risk', $matches[0]->source);
    }

    public function testLeasePendingUsesSkipLockedOnMySqlPlatforms(): void
    {
        $connection = $this->createMySqlSpyConnection();
        $repository = new DatabaseIpGeoRepository($connection);

        self::assertSame([], $repository->leasePending(5, new DateTimeImmutable('2026-06-18T00:00:00+00:00')));
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $connection->lastFetchAllSql);
    }

    public function testLeasePendingMarksRowsProcessingRejectsInvalidLimitAndReclaimsExpiredLeases(): void
    {
        $connection = $this->createConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection, 604800, 60);
        $repository->ensureQueued('203.0.113.81', null, null, 'serving', $queuedAt, 'req-lease-a');
        $repository->ensureQueued('203.0.113.82', null, null, 'serving', $queuedAt->modify('+10 minutes'), 'req-lease-b');

        $leased = $repository->leasePending(1, $queuedAt->modify('+1 minute'));

        self::assertCount(1, $leased);
        self::assertSame('203.0.113.81', $leased[0]->ipAddress);
        self::assertSame('processing', $leased[0]->status);
        self::assertNotNull($leased[0]->leaseToken);
        self::assertSame('processing', $connection->fetchOne(
            'SELECT status FROM ip_geo_lookup_tasks WHERE ip_address = ?',
            ['203.0.113.81'],
        ));
        self::assertSame([], $repository->leasePending(5, $queuedAt->modify('+1 minute')));

        $reclaimed = $repository->leasePending(5, $queuedAt->modify('+2 minutes 5 seconds'));

        self::assertCount(1, $reclaimed);
        self::assertSame('203.0.113.81', $reclaimed[0]->ipAddress);
        self::assertNotSame($leased[0]->leaseToken, $reclaimed[0]->leaseToken);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IP geo cron batch size must be positive.');
        $repository->leasePending(0, $queuedAt);
    }

    public function testLeaseTokenRejectsLateWorkerWritesAfterReclaimAndResolve(): void
    {
        $connection = $this->createConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection, 604800, 60);
        $repository->ensureQueued('203.0.113.87', 'Late browser', 'CN', 'serving', $queuedAt, 'req-late');

        $firstLease = $repository->leasePending(1, $queuedAt->modify('+1 minute'))[0];
        $secondLease = $repository->leasePending(1, $queuedAt->modify('+2 minutes 5 seconds'))[0];

        self::assertNotNull($firstLease->leaseToken);
        self::assertNotNull($secondLease->leaseToken);
        self::assertNotSame($firstLease->leaseToken, $secondLease->leaseToken);

        $repository->markResolved(
            $this->record('203.0.113.87', $queuedAt->modify('+2 minutes 10 seconds'), 'provider-current'),
            $secondLease->leaseToken,
        );
        $repository->markFailed(
            '203.0.113.87',
            'provider-stale',
            'late stale failure',
            $queuedAt->modify('+2 minutes 20 seconds'),
            3,
            30,
            $firstLease->leaseToken,
        );
        $repository->markResolved(
            $this->record('203.0.113.87', $queuedAt->modify('+2 minutes 30 seconds'), 'provider-stale'),
            $firstLease->leaseToken,
        );

        $stored = $repository->findResolved('203.0.113.87');
        $tasks = $repository->searchLookups(['ip_address' => '203.0.113.87', 'limit' => 10]);

        self::assertNotNull($stored);
        self::assertSame('provider-current', $stored->providerId);
        self::assertCount(1, $tasks);
        self::assertSame('resolved', $tasks[0]->status);
        self::assertSame('provider-current', $tasks[0]->providerId);
        self::assertNull($tasks[0]->lastError);
    }

    public function testMarkResolvedWritesCanonicalRecordUpdatesTaskAndSkipsQueueWhileFresh(): void
    {
        $connection = $this->createConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection, 3600, 60, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-18T00:30:00+00:00'));
        $repository->ensureQueued('203.0.113.83', 'Resolve browser', 'CN', 'serving', $queuedAt, 'req-resolve');
        $repository->leasePending(1, $queuedAt);

        $record = $this->record('203.0.113.83', new DateTimeImmutable('2026-06-18T00:01:00+00:00'));
        $repository->markResolved($record);

        $stored = (new DatabaseIpGeoRepository($connection, 3600))->findResolved('203.0.113.83');
        $task = $repository->searchLookups(['status' => 'resolved', 'request_id' => 'req-resolve', 'limit' => 10])[0] ?? null;

        self::assertNotNull($stored);
        self::assertSame('CN-GD-GUANGZHOU', $stored->canonicalGeoCode());
        self::assertSame('provider-cn', $stored->providerId);
        self::assertSame(['country_code' => 'CN', 'city' => 'Guangzhou'], $stored->rawPayloadSummary);
        self::assertNotNull($task);
        self::assertSame('resolved', $task->status);
        self::assertSame('provider-cn', $task->providerId);
        self::assertSame(['req-resolve'], $task->requestIds);
        self::assertSame('CN-GD-GUANGZHOU', $connection->fetchOne(
            'SELECT canonical_geo_code FROM ip_geo_records WHERE ip_address = ?',
            ['203.0.113.83'],
        ));

        $repository->ensureQueued('203.0.113.83', 'Ignored browser', 'US', 'serving', $queuedAt->modify('+5 minutes'), 'req-fresh');
        self::assertSame(['req-resolve'], $repository->searchLookups(['ip_address' => '203.0.113.83', 'limit' => 10])[0]->requestIds);
    }

    public function testMarkResolvedUpdatesExistingCanonicalRecord(): void
    {
        $connection = $this->createConnection();
        $resolvedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection);

        $repository->markResolved($this->record('203.0.113.89', $resolvedAt, 'provider-old'));
        $repository->markResolved($this->record('203.0.113.89', $resolvedAt->modify('+1 hour'), 'provider-new'));

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ip_geo_records WHERE ip_address = ?', ['203.0.113.89']));
        self::assertSame('provider-new', $repository->findResolved('203.0.113.89')?->providerId);
    }

    public function testExpiredResolvedRecordIsQueuedForRefresh(): void
    {
        $connection = $this->createConnection();
        $resolvedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection, 60, 60, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-18T00:02:00+00:00'));

        $repository->markResolved($this->record('203.0.113.84', $resolvedAt));

        self::assertNull($repository->findResolved('203.0.113.84'));

        $repository->ensureQueued('203.0.113.84', 'Refresh browser', 'CN', 'serving', $resolvedAt->modify('+2 minutes'), 'req-refresh');
        $tasks = $repository->searchLookups(['ip_address' => '203.0.113.84', 'status' => 'pending', 'limit' => 10]);

        self::assertCount(1, $tasks);
        self::assertSame(['req-refresh'], $tasks[0]->requestIds);
    }

    public function testMarkFailedRetriesWithBackoffAndTransitionsToDead(): void
    {
        $connection = $this->createConnection();
        $queuedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection);
        $repository->ensureQueued('203.0.113.85', 'Retry browser', 'CN', 'serving', $queuedAt, 'req-fail');
        $repository->leasePending(1, $queuedAt);

        $repository->markFailed('203.0.113.85', 'provider-a', ' first failure ', $queuedAt->modify('+1 minute'), 2, 30);

        $failed = $repository->searchLookups([
            'status' => 'failed',
            'request_id' => 'req-fail',
            'occurred_from' => '2026-06-18T00:00:00+00:00',
            'occurred_to' => '2026-06-18T00:02:00+00:00',
            'limit' => 10,
        ]);
        self::assertCount(1, $failed);
        self::assertSame(1, $failed[0]->attempts);
        self::assertSame('provider-a', $failed[0]->providerId);
        self::assertSame('first failure', $failed[0]->lastError);
        self::assertSame('2026-06-18T00:01:30+00:00', $failed[0]->nextAttemptAt?->format(DATE_ATOM));

        $repository->markFailed('203.0.113.85', 'provider-b', str_repeat('x', 300), $queuedAt->modify('+2 minutes'), 2, 30);
        $dead = $repository->searchLookups(['status' => 'dead', 'ip_address' => '203.0.113.85', 'limit' => 10]);

        self::assertCount(1, $dead);
        self::assertSame(2, $dead[0]->attempts);
        self::assertSame('provider-b', $dead[0]->providerId);
        self::assertSame(str_repeat('x', 255), $dead[0]->lastError);
    }

    public function testMarkFailedWithoutExistingTaskCreatesSyntheticUnknownTask(): void
    {
        $connection = $this->createConnection();
        $failedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $repository = new DatabaseIpGeoRepository($connection);

        $repository->markFailed('203.0.113.90', null, 'temporary failure', $failedAt, 3, 15);

        $tasks = $repository->searchLookups(['ip_address' => '203.0.113.90', 'status' => 'failed', 'limit' => 10]);
        self::assertCount(1, $tasks);
        self::assertSame('unknown', $tasks[0]->source);
        self::assertSame(1, $tasks[0]->attempts);
        self::assertSame('2026-06-18T00:00:15+00:00', $tasks[0]->nextAttemptAt?->format(DATE_ATOM));
        self::assertSame([], $tasks[0]->requestIds);
    }

    public function testSearchSkipsMalformedTaskRowsAndNormalizesScalarRequestIdJson(): void
    {
        $connection = $this->createConnection();
        $now = new DateTimeImmutable('2026-06-18T00:00:00+00:00');
        $invalidHash = hash('sha256', 'bad-ip');
        $connection->insert('ip_geo_lookup_tasks', [
            'ip_hash' => $invalidHash,
            'ip_address' => 'bad-ip',
            'user_agent' => null,
            'region_hint' => null,
            'request_id' => null,
            'request_ids_json' => '[]',
            'source' => 'serving',
            'status' => 'pending',
            'attempts' => 0,
            'provider_id' => null,
            'last_error' => null,
            'next_attempt_at' => $now->format('Y-m-d H:i:s'),
            'leased_until' => null,
            'lease_token' => null,
            'resolved_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $connection->insert('ip_geo_lookup_tasks', [
            'ip_hash' => GeoIpRecord::hashIp('203.0.113.91'),
            'ip_address' => '203.0.113.91',
            'user_agent' => null,
            'region_hint' => null,
            'request_id' => null,
            'request_ids_json' => '"scalar"',
            'source' => 'serving',
            'status' => 'pending',
            'attempts' => 0,
            'provider_id' => null,
            'last_error' => null,
            'next_attempt_at' => $now->format('Y-m-d H:i:s'),
            'leased_until' => null,
            'lease_token' => null,
            'resolved_at' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $tasks = (new DatabaseIpGeoRepository($connection))->searchLookups(['limit' => 10]);

        self::assertCount(1, $tasks);
        self::assertSame('203.0.113.91', $tasks[0]->ipAddress);
        self::assertSame([], $tasks[0]->requestIds);
    }

    public function testNormalizeRequestIdsRejectsNonArrayInputs(): void
    {
        $method = new \ReflectionMethod(DatabaseIpGeoRepository::class, 'normalizeRequestIds');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke(new DatabaseIpGeoRepository($this->createConnection()), 42));
    }

    public function testConstructorRejectsInvalidTtlAndVisibilitySettings(): void
    {
        $connection = $this->createConnection();

        foreach (
            [
                [0, 60, 'IP geo record TTL must be positive.'],
                [60, 0, 'IP geo visibility timeout must be positive.'],
            ] as [$ttl, $visibility, $message]
        ) {
            try {
                new DatabaseIpGeoRepository($connection, $ttl, $visibility);
                self::fail('Expected invalid database IP geo repository settings to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createSchema($connection);

        return $connection;
    }

    public static function createSchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE ip_geo_records (
                ip_hash CHAR(64) PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                country_code VARCHAR(16) NULL,
                country_name VARCHAR(160) NULL,
                region_code VARCHAR(64) NULL,
                region_name VARCHAR(160) NULL,
                city_name VARCHAR(160) NULL,
                latitude NUMERIC NULL,
                longitude NUMERIC NULL,
                timezone VARCHAR(80) NULL,
                canonical_geo_code VARCHAR(255) NULL,
                provider_id VARCHAR(120) NOT NULL,
                resolved_at DATETIME NOT NULL,
                raw_payload_hash CHAR(64) NOT NULL,
                raw_payload_summary_json TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ip_geo_lookup_tasks (
                ip_hash CHAR(64) PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(512) NULL,
                region_hint VARCHAR(64) NULL,
                request_id VARCHAR(160) NULL,
                request_ids_json TEXT NOT NULL,
                source VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                provider_id VARCHAR(120) NULL,
                last_error VARCHAR(255) NULL,
                next_attempt_at DATETIME NULL,
                leased_until DATETIME NULL,
                lease_token VARCHAR(64) NULL,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ip_geo_lookup_request_ids (
                ip_hash CHAR(64) NOT NULL,
                request_id VARCHAR(160) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (ip_hash, request_id)
            )',
        );
    }

    private function createRaceConnection(): RaceOnIpGeoTaskInsertConnection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => RaceOnIpGeoTaskInsertConnection::class,
        ]);
        self::assertInstanceOf(RaceOnIpGeoTaskInsertConnection::class, $connection);
        self::createSchema($connection);

        return $connection;
    }

    private function createLostRaceConnection(): LostRaceOnIpGeoTaskInsertConnection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => LostRaceOnIpGeoTaskInsertConnection::class,
        ]);
        self::assertInstanceOf(LostRaceOnIpGeoTaskInsertConnection::class, $connection);
        self::createSchema($connection);

        return $connection;
    }

    private function createMySqlSpyConnection(): MySqlLeaseSqlSpyConnection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => MySqlLeaseSqlSpyConnection::class,
        ]);
        self::assertInstanceOf(MySqlLeaseSqlSpyConnection::class, $connection);
        self::createSchema($connection);

        return $connection;
    }

    private function record(string $ipAddress, DateTimeImmutable $resolvedAt, string $providerId = 'provider-cn'): GeoIpRecord
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
            providerId: $providerId,
            resolvedAt: $resolvedAt,
            rawPayloadHash: hash('sha256', '{"country_code":"CN","city":"Guangzhou"}'),
            rawPayloadSummary: ['country_code' => 'CN', 'city' => 'Guangzhou'],
        );
    }
}

final class RaceOnIpGeoTaskInsertConnection extends Connection
{
    public ?string $competingRequestId = null;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $types
     */
    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'ip_geo_lookup_tasks' && $this->competingRequestId !== null) {
            $requestId = $this->competingRequestId;
            $this->competingRequestId = null;
            $competing = $data;
            $competing['request_id'] = $requestId;
            $competing['request_ids_json'] = json_encode([$requestId], JSON_THROW_ON_ERROR);
            $competing['user_agent'] = 'Browser first';
            $competing['source'] = 'risk';
            parent::insert($table, $competing, $types);
        }

        return parent::insert($table, $data, $types);
    }
}

final class LostRaceOnIpGeoTaskInsertConnection extends Connection
{
    private bool $throwOnTaskInsert = true;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $types
     */
    public function insert(string $table, array $data, array $types = []): int|string
    {
        if ($table === 'ip_geo_lookup_tasks' && $this->throwOnTaskInsert) {
            $this->throwOnTaskInsert = false;

            throw new SyntheticUniqueConstraintViolationException();
        }

        return parent::insert($table, $data, $types);
    }
}

final class SyntheticUniqueConstraintViolationException extends UniqueConstraintViolationException
{
    public function __construct()
    {
        parent::__construct(new SyntheticDriverException(), null);
    }
}

final class SyntheticDriverException extends \Exception implements \Doctrine\DBAL\Driver\Exception
{
    public function __construct()
    {
        parent::__construct('synthetic unique constraint violation');
    }

    public function getSQLState(): ?string
    {
        return '23000';
    }
}

final class MySqlLeaseSqlSpyConnection extends Connection
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
