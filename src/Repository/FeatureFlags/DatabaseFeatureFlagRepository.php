<?php

declare(strict_types=1);

namespace VertoAD\Repository\FeatureFlags;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\FeatureFlags\FeatureFlag;

final readonly class DatabaseFeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function save(FeatureFlag $flag): FeatureFlag
    {
        $row = $this->rowFromFlag($flag);
        if ($this->find($flag->flag_key) === null) {
            $this->connection->insert('feature_flags', $row);

            return $flag;
        }

        $this->connection->update('feature_flags', $row, ['flag_key' => $flag->flag_key]);

        return $flag;
    }

    public function find(string $flagKey): ?FeatureFlag
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('feature_flags')
            ->where('flag_key = :flag_key')
            ->setParameter('flag_key', trim($flagKey))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('feature_flags')
            ->orderBy('flag_key', 'ASC')
            ->fetchAllAssociative();

        return array_map(fn (array $row): FeatureFlag => $this->hydrate($row), $rows);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'flag_key',
            'environment',
            'enabled',
            'targets_json',
            'percentage_rollout',
            'time_window_json',
            'published',
            'created_at',
            'updated_at',
        ];
    }

    /** @return array<string, int|string|null> */
    private function rowFromFlag(FeatureFlag $flag): array
    {
        return [
            'flag_key' => $flag->flag_key,
            'environment' => $flag->environment,
            'enabled' => $flag->enabled ? 1 : 0,
            'targets_json' => json_encode($flag->targets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'percentage_rollout' => $flag->percentage_rollout,
            'time_window_json' => $flag->time_window === null
                ? null
                : json_encode($flag->time_window, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'published' => $flag->published ? 1 : 0,
            'created_at' => $this->formatDate($flag->created_at),
            'updated_at' => $this->formatDate($flag->updated_at),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FeatureFlag
    {
        $targets = json_decode((string) $row['targets_json'], true, flags: JSON_THROW_ON_ERROR);
        $timeWindow = $row['time_window_json'] === null
            ? null
            : json_decode((string) $row['time_window_json'], true, flags: JSON_THROW_ON_ERROR);

        return new FeatureFlag(
            flag_key: (string) $row['flag_key'],
            environment: (string) $row['environment'],
            enabled: (bool) $row['enabled'],
            targets: is_array($targets) ? $targets : [],
            percentage_rollout: (int) $row['percentage_rollout'],
            time_window: is_array($timeWindow) ? $timeWindow : null,
            published: (bool) $row['published'],
            created_at: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            updated_at: new DateTimeImmutable((string) $row['updated_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
