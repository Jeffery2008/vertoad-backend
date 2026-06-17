<?php

declare(strict_types=1);

namespace VertoAD\Repository\IpGeo;

use DateTimeImmutable;
use VertoAD\Domain\IpGeo\GeoIpLookupTask;
use VertoAD\Domain\IpGeo\GeoIpRecord;

final class InMemoryIpGeoRepository implements IpGeoRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function findResolved(string $ipAddress): ?GeoIpRecord
    {
        $row = $this->rows[GeoIpRecord::hashIp($ipAddress)] ?? null;
        if (($row['status'] ?? null) !== 'resolved') {
            return null;
        }

        return $row['record'] instanceof GeoIpRecord ? $row['record'] : null;
    }

    public function ensureQueued(string $ipAddress, ?string $userAgent, ?string $regionHint, string $source, DateTimeImmutable $queuedAt, ?string $requestId = null): void
    {
        if (@inet_pton($ipAddress) === false) {
            return;
        }

        $hash = GeoIpRecord::hashIp($ipAddress);
        if (($this->rows[$hash]['status'] ?? null) === 'resolved') {
            return;
        }

        $this->rows[$hash] = [
            'status' => $this->rows[$hash]['status'] ?? 'pending',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'region_hint' => $regionHint,
            'request_id' => $this->normalizeRequestId($requestId) ?? $this->rows[$hash]['request_id'] ?? null,
            'request_ids' => $this->mergeRequestIds($this->rows[$hash]['request_ids'] ?? [], $requestId),
            'source' => trim($source),
            'attempts' => (int) ($this->rows[$hash]['attempts'] ?? 0),
            'next_attempt_at' => $this->rows[$hash]['next_attempt_at'] ?? $queuedAt,
            'created_at' => $this->rows[$hash]['created_at'] ?? $queuedAt,
            'lease_token' => $this->rows[$hash]['lease_token'] ?? null,
        ];
    }

    public function searchLookups(array $filters): array
    {
        $items = [];
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        foreach ($this->rows as $row) {
            $task = $this->taskFromRow($row, new DateTimeImmutable());
            if ($task === null || !$this->taskMatches($task, $filters)) {
                continue;
            }

            $items[] = $task;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function leasePending(int $limit, DateTimeImmutable $now): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('IP geo cron batch size must be positive.');
        }

        $tasks = [];
        foreach ($this->rows as $hash => $row) {
            if (!in_array($row['status'] ?? null, ['pending', 'failed'], true)) {
                continue;
            }

            $nextAttemptAt = $row['next_attempt_at'] ?? $now;
            if (!$nextAttemptAt instanceof DateTimeImmutable || $nextAttemptAt > $now) {
                continue;
            }

            $this->rows[$hash]['status'] = 'processing';
            $this->rows[$hash]['lease_token'] = bin2hex(random_bytes(16));
            $task = $this->taskFromRow($this->rows[$hash], $now);
            if ($task === null) {
                continue;
            }
            $tasks[] = $task;

            if (count($tasks) >= $limit) {
                break;
            }
        }

        return $tasks;
    }

    public function markResolved(GeoIpRecord $record, ?string $leaseToken = null): void
    {
        $existing = $this->rows[$record->ipHash()] ?? null;
        if (!$this->leaseCanWrite($existing, $leaseToken)) {
            return;
        }

        $this->rows[$record->ipHash()] = [
            'status' => 'resolved',
            'ip_address' => $record->ipAddress,
            'user_agent' => $this->rows[$record->ipHash()]['user_agent'] ?? null,
            'region_hint' => $this->rows[$record->ipHash()]['region_hint'] ?? null,
            'request_id' => $this->rows[$record->ipHash()]['request_id'] ?? null,
            'request_ids' => $this->rows[$record->ipHash()]['request_ids'] ?? [],
            'source' => $this->rows[$record->ipHash()]['source'] ?? 'unknown',
            'attempts' => (int) ($this->rows[$record->ipHash()]['attempts'] ?? 0) + 1,
            'provider_id' => $record->providerId,
            'record' => $record,
            'next_attempt_at' => null,
            'lease_token' => null,
            'created_at' => $this->rows[$record->ipHash()]['created_at'] ?? $record->resolvedAt,
            'resolved_at' => $record->resolvedAt,
        ];
    }

    public function markFailed(string $ipAddress, ?string $providerId, string $message, DateTimeImmutable $failedAt, int $maxAttempts, int $retryBackoffSeconds, ?string $leaseToken = null): void
    {
        $hash = GeoIpRecord::hashIp($ipAddress);
        $existing = $this->rows[$hash] ?? null;
        if (($existing['status'] ?? null) === 'resolved' || !$this->leaseCanWrite($existing, $leaseToken)) {
            return;
        }

        $attempts = (int) ($this->rows[$hash]['attempts'] ?? 0) + 1;
        $this->rows[$hash]['status'] = $attempts >= $maxAttempts ? 'dead' : 'failed';
        $this->rows[$hash]['attempts'] = $attempts;
        $this->rows[$hash]['provider_id'] = $providerId;
        $this->rows[$hash]['last_error'] = substr(trim($message), 0, 255);
        $this->rows[$hash]['next_attempt_at'] = $failedAt->modify('+' . max(1, $retryBackoffSeconds * $attempts) . ' seconds');
        $this->rows[$hash]['lease_token'] = null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function taskFromRow(array $row, DateTimeImmutable $fallbackCreatedAt): ?GeoIpLookupTask
    {
        if (!is_string($row['ip_address'] ?? null) || @inet_pton($row['ip_address']) === false) {
            return null;
        }

        return new GeoIpLookupTask(
            ipAddress: (string) $row['ip_address'],
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            regionHint: $row['region_hint'] === null ? null : (string) $row['region_hint'],
            attempts: (int) ($row['attempts'] ?? 0),
            createdAt: $row['created_at'] instanceof DateTimeImmutable ? $row['created_at'] : $fallbackCreatedAt,
            requestId: $this->normalizeRequestId($row['request_id'] ?? null),
            requestIds: $this->normalizeRequestIds($row['request_ids'] ?? []),
            source: trim((string) ($row['source'] ?? 'unknown')) ?: 'unknown',
            status: trim((string) ($row['status'] ?? 'pending')) ?: 'pending',
            providerId: $this->nullableString($row['provider_id'] ?? null),
            lastError: $this->nullableString($row['last_error'] ?? null),
            nextAttemptAt: $row['next_attempt_at'] instanceof DateTimeImmutable ? $row['next_attempt_at'] : null,
            resolvedAt: ($row['resolved_at'] ?? null) instanceof DateTimeImmutable ? $row['resolved_at'] : null,
            leaseToken: $this->nullableString($row['lease_token'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function taskMatches(GeoIpLookupTask $task, array $filters): bool
    {
        foreach (['request_id' => $task->requestId, 'ip_address' => $task->ipAddress, 'status' => $task->status, 'source' => $task->source] as $field => $value) {
            $expected = $this->nullableString($filters[$field] ?? null);
            if ($field === 'request_id' && $expected !== null && $value !== $expected && !in_array($expected, $task->requestIds, true)) {
                return false;
            }

            if ($field !== 'request_id' && $expected !== null && $value !== $expected) {
                return false;
            }
        }

        $occurredAt = $task->resolvedAt ?? $task->nextAttemptAt ?? $task->createdAt;
        $from = $this->nullableString($filters['occurred_from'] ?? null);
        if ($from !== null && $occurredAt < new DateTimeImmutable($from)) {
            return false;
        }

        $to = $this->nullableString($filters['occurred_to'] ?? null);
        return $to === null || $occurredAt <= new DateTimeImmutable($to);
    }

    private function normalizeRequestId(mixed $value): ?string
    {
        return $this->nullableString($value);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function leaseCanWrite(?array $row, ?string $leaseToken): bool
    {
        $leaseToken = $this->nullableString($leaseToken);
        if ($leaseToken === null) {
            return true;
        }
        if ($row === null || ($row['status'] ?? null) !== 'processing') {
            return false;
        }

        $currentToken = $this->nullableString($row['lease_token'] ?? null);

        return $currentToken !== null && hash_equals($currentToken, $leaseToken);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $existing
     * @return list<string>
     */
    private function mergeRequestIds(mixed $existing, ?string $requestId): array
    {
        $ids = $this->normalizeRequestIds($existing);
        $requestId = $this->normalizeRequestId($requestId);
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
