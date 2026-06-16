<?php

declare(strict_types=1);

namespace VertoAD\Repository\IpGeo;

use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Domain\IpGeo\GeoIpLookupTask;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;

final readonly class RedisIpGeoRepository implements IpGeoRepositoryInterface
{
    private const DEFAULT_RECORD_TTL_SECONDS = 604800;
    private const DEFAULT_VISIBILITY_TIMEOUT_SECONDS = 300;

    public function __construct(
        private RedisClientInterface $client,
        private string $prefix,
        private int $recordTtlSeconds = self::DEFAULT_RECORD_TTL_SECONDS,
        private int $visibilityTimeoutSeconds = self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS,
    ) {
        if (trim($prefix) === '') {
            throw new \InvalidArgumentException('IP geo Redis prefix is required.');
        }
        if ($recordTtlSeconds < 1) {
            throw new \InvalidArgumentException('IP geo record TTL must be positive.');
        }
        if ($visibilityTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('IP geo visibility timeout must be positive.');
        }
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        return new self(
            RedisClientFactory::fromSettings($settings),
            (string) ($settings['prefix'] ?? 'vertoad:'),
            (int) ($settings['ip_geo_record_ttl_seconds'] ?? self::DEFAULT_RECORD_TTL_SECONDS),
            (int) ($settings['ip_geo_visibility_timeout_seconds'] ?? self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS),
        );
    }

    public function findResolved(string $ipAddress): ?GeoIpRecord
    {
        $payload = $this->client->get($this->recordKey($ipAddress));
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return null;
        }

        return $this->recordFromArray($data);
    }

    public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void
    {
        if (@inet_pton($ipAddress) === false || $this->client->exists($this->recordKey($ipAddress))) {
            return;
        }

        $taskKey = $this->taskKey($ipAddress);
        $created = $this->client->setNxEx($taskKey, $this->serializeTask($ipAddress, $userAgent, $regionHint, $source, $queuedAt, $requestId), $this->recordTtlSeconds);
        $this->client->zAdd($this->lookupIndexKey(), (float) $queuedAt->getTimestamp(), $taskKey);
        if ($created) {
            $this->client->zAdd($this->pendingKey(), (float) $queuedAt->getTimestamp(), $taskKey);
            return;
        }

        $this->appendRequestIdToTask($taskKey, $requestId);
        $this->client->expire($taskKey, $this->recordTtlSeconds);
    }

    public function searchLookups(array $filters): array
    {
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $members = $this->client->zRangeByScore($this->lookupIndexKey(), '-inf', '+inf', 0, $limit * 4);
        $tasks = [];
        foreach (array_reverse($members) as $taskKey) {
            $task = $this->loadTask($taskKey);
            if ($task === null) {
                $this->client->zRem($this->lookupIndexKey(), $taskKey);
                continue;
            }
            if (!$this->taskMatches($task, $filters)) {
                continue;
            }

            $tasks[] = $task;
            if (count($tasks) >= $limit) {
                break;
            }
        }

        return $tasks;
    }

    public function leasePending(int $limit, DateTimeImmutable $now): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('IP geo cron batch size must be positive.');
        }

        $deadline = $now->getTimestamp() + $this->visibilityTimeoutSeconds;
        $members = $this->client->eval(
            <<<'LUA'
local expired = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1])
for _, member in ipairs(expired) do
    redis.call('ZREM', KEYS[2], member)
    redis.call('ZADD', KEYS[1], ARGV[1], member)
end
local leased = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, tonumber(ARGV[2]))
local claimed = {}
for _, member in ipairs(leased) do
    if redis.call('ZREM', KEYS[1], member) == 1 then
        redis.call('ZADD', KEYS[2], ARGV[3], member)
        table.insert(claimed, member)
    end
end
return claimed
LUA,
            [$this->pendingKey(), $this->processingKey()],
            [(string) $now->getTimestamp(), (string) $limit, (string) $deadline],
        );

        $tasks = [];
        foreach ($members as $taskKey) {
            $task = $this->loadTask($taskKey);
            if ($task === null) {
                $this->client->zRem($this->processingKey(), $taskKey);
                continue;
            }
            $tasks[] = $task;
        }

        return $tasks;
    }

    public function markResolved(GeoIpRecord $record): void
    {
        $taskKey = $this->taskKey($record->ipAddress);
        $payload = $this->client->get($taskKey);
        $data = is_string($payload) && $payload !== '' ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : [];
        $data = is_array($data) ? $data : [];
        $data['status'] = 'resolved';
        $data['attempts'] = (int) ($data['attempts'] ?? 0) + 1;
        $data['provider_id'] = $record->providerId;
        $data['last_error'] = null;
        $data['next_attempt_at'] = null;
        $data['resolved_at'] = $record->resolvedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $this->client->setEx($this->recordKey($record->ipAddress), $this->serializeRecord($record), $this->recordTtlSeconds);
        if ($data !== []) {
            $this->client->setEx($taskKey, json_encode($data, JSON_THROW_ON_ERROR), $this->recordTtlSeconds);
            $this->client->zAdd($this->lookupIndexKey(), (float) $record->resolvedAt->getTimestamp(), $taskKey);
        }
        $this->client->zRem($this->pendingKey(), $taskKey);
        $this->client->zRem($this->processingKey(), $taskKey);
    }

    public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds): void
    {
        $taskKey = $this->taskKey($ipAddress);
        $payload = $this->client->get($taskKey);
        $data = is_string($payload) && $payload !== '' ? json_decode($payload, true, flags: JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $attempts = (int) ($data['attempts'] ?? 0) + 1;
        $data['attempts'] = $attempts;
        $data['provider_id'] = $providerId;
        $data['last_error'] = substr(trim($message), 0, 255);
        $nextAttemptAt = $failedAt->modify('+' . max(1, $retryBackoffSeconds * $attempts) . ' seconds');
        $data['next_attempt_at'] = $nextAttemptAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $data['status'] = $attempts >= $maxAttempts ? 'dead' : 'failed';
        $this->client->setEx($taskKey, json_encode($data, JSON_THROW_ON_ERROR), $this->recordTtlSeconds);
        $this->client->zAdd($this->lookupIndexKey(), (float) $failedAt->getTimestamp(), $taskKey);
        $this->client->zRem($this->processingKey(), $taskKey);
        if ($attempts >= $maxAttempts) {
            $this->client->zAdd($this->deadKey(), (float) $failedAt->getTimestamp(), $taskKey);
            return;
        }

        $this->client->zAdd($this->pendingKey(), (float) $nextAttemptAt->getTimestamp(), $taskKey);
    }

    private function loadTask(string $taskKey): ?GeoIpLookupTask
    {
        $payload = $this->client->get($taskKey);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return null;
        }

        return new GeoIpLookupTask(
            ipAddress: (string) $data['ip_address'],
            userAgent: $data['user_agent'] === null ? null : (string) $data['user_agent'],
            regionHint: $data['region_hint'] === null ? null : (string) $data['region_hint'],
            attempts: (int) ($data['attempts'] ?? 0),
            createdAt: new DateTimeImmutable((string) $data['created_at']),
            requestId: $this->nullableString($data['request_id'] ?? null),
            requestIds: $this->normalizeRequestIds($data['request_ids'] ?? []),
            source: $this->nullableString($data['source'] ?? null) ?? 'unknown',
            status: $this->nullableString($data['status'] ?? null) ?? 'pending',
            providerId: $this->nullableString($data['provider_id'] ?? null),
            lastError: $this->nullableString($data['last_error'] ?? null),
            nextAttemptAt: $this->nullableDateTime($data['next_attempt_at'] ?? null),
            resolvedAt: $this->nullableDateTime($data['resolved_at'] ?? null),
        );
    }

    private function serializeTask(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId): string
    {
        $requestId = $this->nullableString($requestId);

        return json_encode([
            'status' => 'pending',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'region_hint' => $regionHint,
            'request_id' => $requestId,
            'request_ids' => $requestId === null ? [] : [$requestId],
            'source' => trim($source),
            'attempts' => 0,
            'created_at' => $queuedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'next_attempt_at' => $queuedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR);
    }

    private function appendRequestIdToTask(string $taskKey, ?string $requestId): void
    {
        $requestId = $this->nullableString($requestId);
        if ($requestId === null) {
            return;
        }

        $payload = $this->client->get($taskKey);
        if (!is_string($payload) || $payload === '') {
            return;
        }

        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return;
        }

        $data['request_id'] = $data['request_id'] ?? $requestId;
        $data['request_ids'] = $this->normalizeRequestIds($data['request_ids'] ?? []);
        $data['request_ids'][] = $requestId;
        $data['request_ids'] = array_values(array_unique($data['request_ids']));
        $this->client->setEx($taskKey, json_encode($data, JSON_THROW_ON_ERROR), $this->recordTtlSeconds);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function taskMatches(GeoIpLookupTask $task, array $filters): bool
    {
        $requestId = $this->nullableString($filters['request_id'] ?? null);
        if ($requestId !== null && $task->requestId !== $requestId && !in_array($requestId, $task->requestIds, true)) {
            return false;
        }

        foreach (['ip_address' => $task->ipAddress, 'status' => $task->status, 'source' => $task->source] as $field => $value) {
            $expected = $this->nullableString($filters[$field] ?? null);
            if ($expected !== null && $value !== $expected) {
                return false;
            }
        }

        $occurredAt = $task->resolvedAt ?? $task->nextAttemptAt ?? $task->createdAt;
        $from = $this->nullableDateTime($filters['occurred_from'] ?? null);
        if ($from !== null && $occurredAt < $from) {
            return false;
        }

        $to = $this->nullableDateTime($filters['occurred_to'] ?? null);
        return $to === null || $occurredAt <= $to;
    }

    private function serializeRecord(GeoIpRecord $record): string
    {
        return json_encode([
            'ip_address' => $record->ipAddress,
            'country_code' => $record->countryCode,
            'country_name' => $record->countryName,
            'region_code' => $record->regionCode,
            'region_name' => $record->regionName,
            'city_name' => $record->cityName,
            'latitude' => $record->latitude,
            'longitude' => $record->longitude,
            'timezone' => $record->timezone,
            'provider_id' => $record->providerId,
            'resolved_at' => $record->resolvedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'raw_payload_hash' => $record->rawPayloadHash,
            'raw_payload_summary' => $record->rawPayloadSummary,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function recordFromArray(array $data): GeoIpRecord
    {
        $summary = $data['raw_payload_summary'] ?? [];

        return new GeoIpRecord(
            ipAddress: (string) $data['ip_address'],
            countryCode: $data['country_code'] === null ? null : (string) $data['country_code'],
            countryName: $data['country_name'] === null ? null : (string) $data['country_name'],
            regionCode: $data['region_code'] === null ? null : (string) $data['region_code'],
            regionName: $data['region_name'] === null ? null : (string) $data['region_name'],
            cityName: $data['city_name'] === null ? null : (string) $data['city_name'],
            latitude: $data['latitude'] === null ? null : (float) $data['latitude'],
            longitude: $data['longitude'] === null ? null : (float) $data['longitude'],
            timezone: $data['timezone'] === null ? null : (string) $data['timezone'],
            providerId: (string) $data['provider_id'],
            resolvedAt: new DateTimeImmutable((string) $data['resolved_at']),
            rawPayloadHash: (string) $data['raw_payload_hash'],
            rawPayloadSummary: is_array($summary) ? $summary : [],
        );
    }

    private function recordKey(string $ipAddress): string
    {
        return $this->prefix . 'ip-geo:record:' . GeoIpRecord::hashIp($ipAddress);
    }

    private function taskKey(string $ipAddress): string
    {
        return $this->prefix . 'ip-geo:task:' . GeoIpRecord::hashIp($ipAddress);
    }

    private function pendingKey(): string
    {
        return $this->prefix . 'ip-geo:pending';
    }

    private function processingKey(): string
    {
        return $this->prefix . 'ip-geo:processing';
    }

    private function deadKey(): string
    {
        return $this->prefix . 'ip-geo:dead';
    }

    private function lookupIndexKey(): string
    {
        return $this->prefix . 'ip-geo:lookups';
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        $value = $this->nullableString($value);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    /**
     * @return list<string>
     */
    private function normalizeRequestIds(mixed $value): array
    {
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
}
