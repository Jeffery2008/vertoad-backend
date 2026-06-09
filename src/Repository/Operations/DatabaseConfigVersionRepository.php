<?php

declare(strict_types=1);

namespace VertoAD\Repository\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Operations\ConfigVersion;

final readonly class DatabaseConfigVersionRepository implements ConfigVersionRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(ConfigVersion $version): ConfigVersion
    {
        $this->connection->insert('system_config_versions', [
            'version_id' => $version->version_id,
            'config_key' => $version->config_key,
            'version' => $version->version_number,
            'value_json' => json_encode($version->value, JSON_THROW_ON_ERROR),
            'created_by_user_id' => $version->created_by_user_id,
            'created_at' => $this->formatDate($version->created_at),
        ]);

        return $version;
    }

    public function find(string $versionId): ?ConfigVersion
    {
        $row = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('system_config_versions')
            ->where('version_id = :version_id')
            ->setParameter('version_id', trim($versionId))
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function listByKey(string $configKey): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(...$this->columns())
            ->from('system_config_versions')
            ->where('config_key = :config_key')
            ->orderBy('version', 'ASC')
            ->setParameter('config_key', trim($configKey))
            ->fetchAllAssociative();

        return array_map(fn (array $row): ConfigVersion => $this->hydrate($row), $rows);
    }

    public function nextVersionNumber(string $configKey): int
    {
        $version = $this->connection->createQueryBuilder()
            ->select('MAX(version)')
            ->from('system_config_versions')
            ->where('config_key = :config_key')
            ->setParameter('config_key', trim($configKey))
            ->fetchOne();

        return (int) $version + 1;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return ['version_id', 'config_key', 'version', 'value_json', 'created_by_user_id', 'created_at'];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ConfigVersion
    {
        $value = json_decode((string) $row['value_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            $value = [];
        }

        return new ConfigVersion(
            version_id: (string) $row['version_id'],
            config_key: (string) $row['config_key'],
            version_number: (int) $row['version'],
            value: $value,
            created_by_user_id: (int) $row['created_by_user_id'],
            created_at: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
