<?php

declare(strict_types=1);

namespace VertoAD\Service\FeatureFlags;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\FeatureFlags\FeatureFlag;
use VertoAD\Repository\FeatureFlags\FeatureFlagRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class FeatureFlagService
{
    public function __construct(
        private FeatureFlagRepositoryInterface $flags,
        private AuditLogService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrUpdate(array $payload, int $actorUserId): array
    {
        $flagKey = $this->flagKey((string) ($payload['flag_key'] ?? ''));
        $environment = $this->environment((string) ($payload['environment'] ?? ''));
        $enabled = (bool) ($payload['enabled'] ?? false);
        $targets = $this->targets($payload['targets'] ?? []);
        $percentage = $this->percentage($payload['percentage_rollout'] ?? 0);
        $timeWindow = $this->timeWindow($payload['time_window'] ?? null);
        $existing = $this->flags->find($flagKey);
        $now = new DateTimeImmutable();

        $flag = new FeatureFlag(
            flag_key: $flagKey,
            environment: $environment,
            enabled: $enabled,
            targets: $targets,
            percentage_rollout: $percentage,
            time_window: $timeWindow,
            published: $existing?->published ?? false,
            created_at: $existing?->created_at ?? $now,
            updated_at: $now,
        );

        $saved = $this->flags->save($flag);
        $this->audit->record(
            action: 'feature_flag.updated',
            subjectType: 'feature_flag',
            actorUserId: $actorUserId,
            metadata: ['flag_key' => $flagKey, 'environment' => $environment],
        );

        return $saved->toArray();
    }

    /**
     * @param array<string, mixed>|FeatureFlag $flag
     * @return array<string, mixed>
     */
    public function publish(array|FeatureFlag $flag, int $actorUserId): array
    {
        $record = is_array($flag) ? $this->flags->find((string) $flag['flag_key']) : $flag;
        if ($record === null) {
            throw new InvalidArgumentException('Feature flag not found.');
        }

        $published = new FeatureFlag(
            flag_key: $record->flag_key,
            environment: $record->environment,
            enabled: $record->enabled,
            targets: $record->targets,
            percentage_rollout: $record->percentage_rollout,
            time_window: $record->time_window,
            published: true,
            created_at: $record->created_at,
            updated_at: new DateTimeImmutable(),
        );

        $saved = $this->flags->save($published);
        $this->audit->record(
            action: 'feature_flag.published',
            subjectType: 'feature_flag',
            actorUserId: $actorUserId,
            metadata: ['flag_key' => $saved->flag_key, 'environment' => $saved->environment],
        );

        return $saved->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFlags(): array
    {
        return array_map(static fn (FeatureFlag $flag): array => $flag->toArray(), $this->flags->all());
    }

    /**
     * @param array<string, mixed> $context
     * @return array{flag_key:string,enabled:bool,reason:string}
     */
    public function evaluate(string $flagKey, array $context): array
    {
        $flag = $this->flags->find($flagKey);
        if ($flag === null || !$flag->published || !$flag->enabled) {
            return ['flag_key' => $flagKey, 'enabled' => false, 'reason' => 'flag_disabled'];
        }

        if (($context['environment'] ?? null) !== $flag->environment) {
            return ['flag_key' => $flagKey, 'enabled' => false, 'reason' => 'environment_mismatch'];
        }

        if (!$this->inTimeWindow($flag, (string) ($context['now'] ?? (new DateTimeImmutable())->format(DATE_ATOM)))) {
            return ['flag_key' => $flagKey, 'enabled' => false, 'reason' => 'outside_time_window'];
        }

        $hasTargets = $flag->targets !== [];
        if (!$this->matchesTargets($flag, $context)) {
            return ['flag_key' => $flagKey, 'enabled' => false, 'reason' => 'target_miss'];
        }

        if (($flag->percentage_rollout <= 0 || !$hasTargets) && !$this->inRollout($flag, (string) ($context['rollout_seed'] ?? json_encode($context, JSON_THROW_ON_ERROR)))) {
            return ['flag_key' => $flagKey, 'enabled' => false, 'reason' => 'rollout_miss'];
        }

        return ['flag_key' => $flagKey, 'enabled' => true, 'reason' => 'target_match'];
    }

    private function inTimeWindow(FeatureFlag $flag, string $now): bool
    {
        if ($flag->time_window === null) {
            return true;
        }

        $current = new DateTimeImmutable($now);

        return $current >= new DateTimeImmutable($flag->time_window['starts_at'])
            && $current <= new DateTimeImmutable($flag->time_window['ends_at']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function matchesTargets(FeatureFlag $flag, array $context): bool
    {
        if ($flag->targets === []) {
            return true;
        }

        $checks = [
            'organization_ids' => $context['organization_id'] ?? null,
            'user_ids' => $context['user_id'] ?? null,
            'site_ids' => $context['site_id'] ?? null,
            'slot_ids' => $context['slot_id'] ?? null,
        ];

        foreach ($checks as $targetKey => $value) {
            if ($value !== null && in_array($value, $flag->targets[$targetKey] ?? [], true)) {
                return true;
            }
        }

        foreach (($context['roles'] ?? []) as $role) {
            if (in_array($role, $flag->targets['roles'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function inRollout(FeatureFlag $flag, string $seed): bool
    {
        if ($flag->percentage_rollout >= 100) {
            return true;
        }

        if ($flag->percentage_rollout <= 0) {
            return false;
        }

        $bucket = hexdec(substr(sha1($flag->flag_key . '|' . $seed), 0, 8)) % 100;

        return $bucket < $flag->percentage_rollout;
    }

    private function flagKey(string $flagKey): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $flagKey) !== 1) {
            throw new InvalidArgumentException('Feature flag key must use dot-separated lowercase identifiers.');
        }

        return $flagKey;
    }

    private function environment(string $environment): string
    {
        if (!in_array($environment, ['local', 'staging', 'prod'], true)) {
            throw new InvalidArgumentException('Feature flag environment is invalid.');
        }

        return $environment;
    }

    private function percentage(mixed $percentage): int
    {
        if (!is_int($percentage) || $percentage < 0 || $percentage > 100) {
            throw new InvalidArgumentException('Feature flag percentage_rollout must be between 0 and 100.');
        }

        return $percentage;
    }

    /**
     * @return array<string, list<int|string>>
     */
    private function targets(mixed $targets): array
    {
        if (!is_array($targets)) {
            throw new InvalidArgumentException('Feature flag targets must be an object.');
        }

        $allowed = ['organization_ids', 'user_ids', 'roles', 'site_ids', 'slot_ids'];
        $normalized = [];
        foreach ($targets as $key => $values) {
            if (!in_array($key, $allowed, true) || !is_array($values)) {
                throw new InvalidArgumentException('Feature flag targets are invalid.');
            }

            $normalized[$key] = array_values($values);
        }

        return $normalized;
    }

    /**
     * @return array{starts_at:string,ends_at:string}|null
     */
    private function timeWindow(mixed $timeWindow): ?array
    {
        if ($timeWindow === null) {
            return null;
        }

        if (!is_array($timeWindow)) {
            throw new InvalidArgumentException('Feature flag time_window must be an object.');
        }

        $startsAt = (string) ($timeWindow['starts_at'] ?? '');
        $endsAt = (string) ($timeWindow['ends_at'] ?? '');
        if ($startsAt === '' || $endsAt === '') {
            throw new InvalidArgumentException('Feature flag time_window requires starts_at and ends_at.');
        }

        if (new DateTimeImmutable($startsAt) >= new DateTimeImmutable($endsAt)) {
            throw new InvalidArgumentException('Feature flag time_window starts_at must be before ends_at.');
        }

        return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
    }
}
