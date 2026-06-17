<?php

declare(strict_types=1);

namespace VertoAD\Repository\IpGeo;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use VertoAD\Domain\IpGeo\GeoIpLookupTask;
use VertoAD\Domain\IpGeo\GeoIpRecord;

final readonly class DatabaseIpGeoRepository implements IpGeoRepositoryInterface
{
    private const DEFAULT_RECORD_TTL_SECONDS = 604800;
    private const DEFAULT_VISIBILITY_TIMEOUT_SECONDS = 300;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param null|callable(): DateTimeImmutable $clock
     */
    public function __construct(
        private Connection $connection,
        private int $recordTtlSeconds = self::DEFAULT_RECORD_TTL_SECONDS,
        private int $visibilityTimeoutSeconds = self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS,
        ?callable $clock = null,
    ) {
        if ($recordTtlSeconds < 1) {
            throw new \InvalidArgumentException('IP geo record TTL must be positive.');
        }
        if ($visibilityTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('IP geo visibility timeout must be positive.');
        }

        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable());
    }

    public function findResolved(string $ipAddress): ?GeoIpRecord
    {
        if (@inet_pton($ipAddress) === false) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(...$this->recordColumns())
            ->from('ip_geo_records')
            ->where('ip_hash = :ip_hash')
            ->setParameter('ip_hash', GeoIpRecord::hashIp($ipAddress))
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $resolvedAt = $this->dateTime($row['resolved_at'] ?? null);
        if ($resolvedAt === null || $resolvedAt < $this->now()->modify('-' . $this->recordTtlSeconds . ' seconds')) {
            return null;
        }

        return $this->recordFromRow($row);
    }

    public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void
    {
        if (@inet_pton($ipAddress) === false || $this->findResolved($ipAddress) !== null) {
            return;
        }

        $ipHash = GeoIpRecord::hashIp($ipAddress);
        $requestId = $this->nullableString($requestId);

        $this->connection->transactional(function () use ($ipAddress, $ipHash, $userAgent, $regionHint, $source, $queuedAt, $requestId): void {
            $row = $this->findTaskRow($ipHash);
            [$values, $requestIds] = $this->queuedTaskValues($row, $ipAddress, $userAgent, $regionHint, $source, $queuedAt, $requestId);

            if ($row === false) {
                try {
                    $this->connection->insert('ip_geo_lookup_tasks', [
                        'ip_hash' => $ipHash,
                        ...$values,
                        'created_at' => $this->formatDate($queuedAt),
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    $row = $this->findTaskRow($ipHash);
                    if ($row === false) {
                        throw $exception;
                    }

                    [$values, $requestIds] = $this->queuedTaskValues($row, $ipAddress, $userAgent, $regionHint, $source, $queuedAt, $requestId);
                    $this->connection->update('ip_geo_lookup_tasks', $values, ['ip_hash' => $ipHash]);
                }
            } else {
                $this->connection->update('ip_geo_lookup_tasks', $values, ['ip_hash' => $ipHash]);
            }

            $this->syncRequestIdIndex($ipHash, $requestIds, $queuedAt);
        });
    }

    public function searchLookups(array $filters): array
    {
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $query = $this->connection->createQueryBuilder()
            ->select(...$this->taskColumns('t'))
            ->from('ip_geo_lookup_tasks', 't')
            ->orderBy('COALESCE(t.resolved_at, t.next_attempt_at, t.created_at)', 'DESC')
            ->addOrderBy('t.ip_hash', 'ASC')
            ->setMaxResults($limit);

        $requestId = $this->nullableString($filters['request_id'] ?? null);
        if ($requestId !== null) {
            $query->andWhere(
                '(t.request_id = :request_id OR EXISTS (
                    SELECT 1 FROM ip_geo_lookup_request_ids rid
                    WHERE rid.ip_hash = t.ip_hash AND rid.request_id = :request_id
                ))',
            )->setParameter('request_id', $requestId);
        }

        foreach (['ip_address', 'status', 'source'] as $field) {
            $value = $this->nullableString($filters[$field] ?? null);
            if ($value !== null) {
                $query->andWhere('t.' . $field . ' = :' . $field)
                    ->setParameter($field, $value);
            }
        }

        $from = $this->dateTime($filters['occurred_from'] ?? null);
        if ($from !== null) {
            $query->andWhere('COALESCE(t.resolved_at, t.next_attempt_at, t.created_at) >= :occurred_from')
                ->setParameter('occurred_from', $this->formatDate($from));
        }

        $to = $this->dateTime($filters['occurred_to'] ?? null);
        if ($to !== null) {
            $query->andWhere('COALESCE(t.resolved_at, t.next_attempt_at, t.created_at) <= :occurred_to')
                ->setParameter('occurred_to', $this->formatDate($to));
        }

        $tasks = [];
        foreach ($query->fetchAllAssociative() as $row) {
            $task = $this->taskFromRow($row);
            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    public function leasePending(int $limit, DateTimeImmutable $now): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('IP geo cron batch size must be positive.');
        }

        return $this->connection->transactional(function () use ($limit, $now): array {
            $this->connection->executeStatement(
                "UPDATE ip_geo_lookup_tasks
                 SET status = 'pending', next_attempt_at = :now, leased_until = NULL, lease_token = NULL, updated_at = :now
                 WHERE status = 'processing' AND leased_until IS NOT NULL AND leased_until <= :now",
                ['now' => $this->formatDate($now)],
            );

            $sql = 'SELECT ' . implode(', ', $this->taskColumns()) . '
                FROM ip_geo_lookup_tasks
                WHERE status IN (\'pending\', \'failed\')
                    AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                ORDER BY next_attempt_at ASC, created_at ASC, ip_hash ASC
                LIMIT ' . $limit;
            if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }

            $rows = $this->connection->fetchAllAssociative($sql, ['now' => $this->formatDate($now)]);
            $leasedUntil = $now->modify('+' . $this->visibilityTimeoutSeconds . ' seconds');
            foreach ($rows as $row) {
                $leaseToken = bin2hex(random_bytes(16));
                $this->connection->update(
                    'ip_geo_lookup_tasks',
                    [
                        'status' => 'processing',
                        'leased_until' => $this->formatDate($leasedUntil),
                        'lease_token' => $leaseToken,
                        'updated_at' => $this->formatDate($now),
                    ],
                    [
                        'ip_hash' => (string) $row['ip_hash'],
                    ],
                );
                $row['status'] = 'processing';
                $row['leased_until'] = $this->formatDate($leasedUntil);
                $row['lease_token'] = $leaseToken;
                $row['updated_at'] = $this->formatDate($now);
                $claimedRows[] = $row;
            }

            $tasks = [];
            foreach ($claimedRows ?? [] as $row) {
                $task = $this->taskFromRow($row);
                if ($task !== null) {
                    $tasks[] = $task;
                }
            }

            return $tasks;
        });
    }

    public function markResolved(GeoIpRecord $record, ?string $leaseToken = null): void
    {
        $ipHash = $record->ipHash();
        $now = $this->now();

        $this->connection->transactional(function () use ($record, $ipHash, $now, $leaseToken): void {
            $row = $this->findTaskRow($ipHash);
            if (!$this->leaseCanWrite($row, $leaseToken)) {
                return;
            }

            $recordRow = [
                'ip_address' => $record->ipAddress,
                'country_code' => $record->countryCode,
                'country_name' => $record->countryName,
                'region_code' => $record->regionCode,
                'region_name' => $record->regionName,
                'city_name' => $record->cityName,
                'latitude' => $record->latitude,
                'longitude' => $record->longitude,
                'timezone' => $record->timezone,
                'canonical_geo_code' => $record->canonicalGeoCode(),
                'provider_id' => $record->providerId,
                'resolved_at' => $this->formatDate($record->resolvedAt),
                'raw_payload_hash' => $record->rawPayloadHash,
                'raw_payload_summary_json' => $this->encodeJson($record->rawPayloadSummary),
                'updated_at' => $this->formatDate($now),
            ];

            if ($this->recordExists($ipHash)) {
                $this->connection->update('ip_geo_records', $recordRow, ['ip_hash' => $ipHash]);
            } else {
                $this->connection->insert('ip_geo_records', [
                    'ip_hash' => $ipHash,
                    ...$recordRow,
                    'created_at' => $this->formatDate($now),
                ]);
            }

            $requestIds = $this->requestIdsFromRow($row === false ? [] : $row);
            $taskRow = [
                'ip_address' => $record->ipAddress,
                'user_agent' => $row === false ? null : $row['user_agent'],
                'region_hint' => $row === false ? null : $row['region_hint'],
                'request_id' => $row === false ? null : $row['request_id'],
                'request_ids_json' => $this->encodeJson($requestIds),
                'source' => $row === false ? 'unknown' : $this->source($row['source'] ?? 'unknown'),
                'status' => 'resolved',
                'attempts' => (int) ($row === false ? 0 : $row['attempts']) + 1,
                'provider_id' => $record->providerId,
                'last_error' => null,
                'next_attempt_at' => null,
                'leased_until' => null,
                'lease_token' => null,
                'resolved_at' => $this->formatDate($record->resolvedAt),
                'updated_at' => $this->formatDate($now),
            ];

            if ($row === false) {
                $this->connection->insert('ip_geo_lookup_tasks', [
                    'ip_hash' => $ipHash,
                    ...$taskRow,
                    'created_at' => $this->formatDate($record->resolvedAt),
                ]);
            } else {
                $this->connection->update('ip_geo_lookup_tasks', $taskRow, ['ip_hash' => $ipHash]);
            }

            $this->syncRequestIdIndex($ipHash, $requestIds, $now);
        });
    }

    public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds, ?string $leaseToken = null): void
    {
        $ipHash = GeoIpRecord::hashIp($ipAddress);
        $this->connection->transactional(function () use ($ipAddress, $ipHash, $providerId, $message, $failedAt, $maxAttempts, $retryBackoffSeconds, $leaseToken): void {
            $row = $this->findTaskRow($ipHash);
            if (($row !== false && $this->nullableString($row['status'] ?? null) === 'resolved') || !$this->leaseCanWrite($row, $leaseToken)) {
                return;
            }

            $attempts = (int) ($row === false ? 0 : $row['attempts']) + 1;
            $nextAttemptAt = $failedAt->modify('+' . max(1, $retryBackoffSeconds * $attempts) . ' seconds');
            $requestIds = $this->requestIdsFromRow($row === false ? [] : $row);
            $values = [
                'ip_address' => $ipAddress,
                'user_agent' => $row === false ? null : $row['user_agent'],
                'region_hint' => $row === false ? null : $row['region_hint'],
                'request_id' => $row === false ? null : $row['request_id'],
                'request_ids_json' => $this->encodeJson($requestIds),
                'source' => $row === false ? 'unknown' : $this->source($row['source'] ?? 'unknown'),
                'status' => $attempts >= $maxAttempts ? 'dead' : 'failed',
                'attempts' => $attempts,
                'provider_id' => $providerId,
                'last_error' => substr(trim($message), 0, 255),
                'next_attempt_at' => $this->formatDate($nextAttemptAt),
                'leased_until' => null,
                'lease_token' => null,
                'resolved_at' => null,
                'updated_at' => $this->formatDate($failedAt),
            ];

            if ($row === false) {
                $this->connection->insert('ip_geo_lookup_tasks', [
                    'ip_hash' => $ipHash,
                    ...$values,
                    'created_at' => $this->formatDate($failedAt),
                ]);
            } else {
                $this->connection->update('ip_geo_lookup_tasks', $values, ['ip_hash' => $ipHash]);
            }
        });
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return list<string> */
    private function recordColumns(): array
    {
        return [
            'ip_hash',
            'ip_address',
            'country_code',
            'country_name',
            'region_code',
            'region_name',
            'city_name',
            'latitude',
            'longitude',
            'timezone',
            'canonical_geo_code',
            'provider_id',
            'resolved_at',
            'raw_payload_hash',
            'raw_payload_summary_json',
            'created_at',
            'updated_at',
        ];
    }

    /** @return list<string> */
    private function taskColumns(?string $alias = null): array
    {
        $prefix = $alias === null ? '' : $alias . '.';

        return [
            $prefix . 'ip_hash',
            $prefix . 'ip_address',
            $prefix . 'user_agent',
            $prefix . 'region_hint',
            $prefix . 'request_id',
            $prefix . 'request_ids_json',
            $prefix . 'source',
            $prefix . 'status',
            $prefix . 'attempts',
            $prefix . 'provider_id',
            $prefix . 'last_error',
            $prefix . 'next_attempt_at',
            $prefix . 'leased_until',
            $prefix . 'lease_token',
            $prefix . 'resolved_at',
            $prefix . 'created_at',
            $prefix . 'updated_at',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recordFromRow(array $row): GeoIpRecord
    {
        $summary = json_decode((string) $row['raw_payload_summary_json'], true, flags: JSON_THROW_ON_ERROR);

        return new GeoIpRecord(
            ipAddress: (string) $row['ip_address'],
            countryCode: $this->nullableString($row['country_code'] ?? null),
            countryName: $this->nullableString($row['country_name'] ?? null),
            regionCode: $this->nullableString($row['region_code'] ?? null),
            regionName: $this->nullableString($row['region_name'] ?? null),
            cityName: $this->nullableString($row['city_name'] ?? null),
            latitude: $row['latitude'] === null ? null : (float) $row['latitude'],
            longitude: $row['longitude'] === null ? null : (float) $row['longitude'],
            timezone: $this->nullableString($row['timezone'] ?? null),
            providerId: (string) $row['provider_id'],
            resolvedAt: $this->dateTime($row['resolved_at']) ?? new DateTimeImmutable('@0'),
            rawPayloadHash: (string) $row['raw_payload_hash'],
            rawPayloadSummary: is_array($summary) ? $summary : [],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function taskFromRow(array $row): ?GeoIpLookupTask
    {
        try {
            return new GeoIpLookupTask(
                ipAddress: (string) $row['ip_address'],
                userAgent: $this->nullableString($row['user_agent'] ?? null),
                regionHint: $this->nullableString($row['region_hint'] ?? null),
                attempts: (int) ($row['attempts'] ?? 0),
                createdAt: $this->dateTime($row['created_at'] ?? null) ?? $this->now(),
                requestId: $this->nullableString($row['request_id'] ?? null),
                requestIds: $this->requestIdsFromRow($row),
                source: $this->source($row['source'] ?? 'unknown'),
                status: $this->source($row['status'] ?? 'pending'),
                providerId: $this->nullableString($row['provider_id'] ?? null),
                lastError: $this->nullableString($row['last_error'] ?? null),
                nextAttemptAt: $this->dateTime($row['next_attempt_at'] ?? null),
                resolvedAt: $this->dateTime($row['resolved_at'] ?? null),
                leaseToken: $this->nullableString($row['lease_token'] ?? null),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    private function findTaskRow(string $ipHash): array|false
    {
        return $this->connection->createQueryBuilder()
            ->select(...$this->taskColumns())
            ->from('ip_geo_lookup_tasks')
            ->where('ip_hash = :ip_hash')
            ->setParameter('ip_hash', $ipHash)
            ->fetchAssociative();
    }

    private function recordExists(string $ipHash): bool
    {
        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('ip_geo_records')
            ->where('ip_hash = :ip_hash')
            ->setParameter('ip_hash', $ipHash)
            ->fetchOne() > 0;
    }

    /**
     * @param array<string, mixed>|false $row
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function queuedTaskValues(array|false $row, string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId): array
    {
        $rowData = $row === false ? [] : $row;
        $requestIds = $this->mergeRequestIds($rowData['request_ids_json'] ?? '[]', $requestId);
        $existingStatus = $this->nullableString($rowData['status'] ?? null);
        $isResolvedRefresh = $existingStatus === 'resolved';
        $status = $isResolvedRefresh ? 'pending' : ($existingStatus ?? 'pending');
        $nextAttemptAt = $row === false || $isResolvedRefresh
            ? $queuedAt
            : $this->dateTime($row['next_attempt_at'] ?? null);
        $isProcessing = $status === 'processing';

        return [[
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'region_hint' => $regionHint,
            'request_id' => $this->nullableString($rowData['request_id'] ?? null) ?? $requestId,
            'request_ids_json' => $this->encodeJson($requestIds),
            'source' => $this->source($source),
            'status' => $status,
            'attempts' => (int) ($rowData['attempts'] ?? 0),
            'provider_id' => $row === false || $isResolvedRefresh ? null : $rowData['provider_id'],
            'last_error' => $row === false || $isResolvedRefresh ? null : $rowData['last_error'],
            'next_attempt_at' => $nextAttemptAt === null ? null : $this->formatDate($nextAttemptAt),
            'leased_until' => $isProcessing ? $rowData['leased_until'] : null,
            'lease_token' => $isProcessing ? $rowData['lease_token'] : null,
            'resolved_at' => $row === false || $isResolvedRefresh ? null : $rowData['resolved_at'],
            'updated_at' => $this->formatDate($queuedAt),
        ], $requestIds];
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function leaseCanWrite(array|false $row, ?string $leaseToken): bool
    {
        $leaseToken = $this->nullableString($leaseToken);
        if ($leaseToken === null) {
            return true;
        }
        if ($row === false || $this->nullableString($row['status'] ?? null) !== 'processing') {
            return false;
        }

        $currentToken = $this->nullableString($row['lease_token'] ?? null);

        return $currentToken !== null && hash_equals($currentToken, $leaseToken);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function requestIdsFromRow(array $row): array
    {
        return $this->normalizeRequestIds($row['request_ids_json'] ?? []);
    }

    /**
     * @param mixed $existing
     * @return list<string>
     */
    private function mergeRequestIds(mixed $existing, ?string $requestId): array
    {
        $ids = $this->normalizeRequestIds($existing);
        if ($requestId !== null) {
            $ids[] = $requestId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function normalizeRequestIds(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $item = $this->nullableString($item);
            if ($item !== null) {
                $ids[] = $item;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string> $requestIds
     */
    private function syncRequestIdIndex(string $ipHash, array $requestIds, DateTimeImmutable $createdAt): void
    {
        foreach ($requestIds as $requestId) {
            try {
                $this->connection->insert('ip_geo_lookup_request_ids', [
                    'ip_hash' => $ipHash,
                    'request_id' => $requestId,
                    'created_at' => $this->formatDate($createdAt),
                ]);
            } catch (UniqueConstraintViolationException) {
            }
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function source(mixed $value): string
    {
        return $this->nullableString($value) ?? 'unknown';
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param mixed $value
     */
    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
