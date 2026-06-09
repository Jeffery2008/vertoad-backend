<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;

final class SystemConfigRepository implements SystemConfigRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestValue(string $configKey): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('value_json')
            ->from('system_config_versions')
            ->where('config_key = :config_key')
            ->orderBy('version', 'DESC')
            ->setMaxResults(1)
            ->setParameter('config_key', $configKey)
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string) $row['value_json'], true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    public function listLatestValues(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('config_key', 'value_json')
            ->from('system_config_versions', 'current_versions')
            ->where('version = (SELECT MAX(latest_versions.version) FROM system_config_versions latest_versions WHERE latest_versions.config_key = current_versions.config_key)')
            ->orderBy('config_key', 'ASC')
            ->fetchAllAssociative();

        $values = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value_json'], true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $values[(string) $row['config_key']] = $decoded;
            }
        }

        return $values;
    }
}
