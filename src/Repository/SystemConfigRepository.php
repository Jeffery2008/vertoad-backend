<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;

final class SystemConfigRepository
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
}
