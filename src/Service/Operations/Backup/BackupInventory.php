<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class BackupInventory
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function configurationSnapshot(DateTimeImmutable $createdAt): array
    {
        $versions = [];
        if ($this->tableExists('system_config_versions')) {
            foreach ($this->connection->createQueryBuilder()
                ->select('version_id', 'config_key', 'version', 'value_json', 'created_by_user_id', 'created_at')
                ->from('system_config_versions')
                ->orderBy('config_key', 'ASC')
                ->addOrderBy('version', 'ASC')
                ->fetchAllAssociative() as $row) {
                $value = json_decode((string) $row['value_json'], true, flags: JSON_THROW_ON_ERROR);
                $versions[] = [
                    'version_id' => (string) $row['version_id'],
                    'config_key' => (string) $row['config_key'],
                    'version' => (int) $row['version'],
                    'value' => is_array($value) ? $value : [],
                    'created_by_user_id' => (int) $row['created_by_user_id'],
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }

        return [
            'schema' => 'vertoad-config-backup-v1',
            'created_at' => $createdAt->format(DATE_ATOM),
            'system_config_versions' => $versions,
        ];
    }

    /** @return list<string> */
    public function criticalObjectKeys(): array
    {
        $keys = [];
        foreach ([['creative_assets', null], ['withdrawal_proofs', "status = 'confirmed'"]] as [$table, $where]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $query = $this->connection->createQueryBuilder()->select('object_key')->from($table);
            if ($where !== null) {
                $query->where($where);
            }
            foreach ($query->fetchFirstColumn() as $key) {
                if (is_scalar($key) && trim((string) $key) !== '') {
                    $keys[] = trim((string) $key);
                }
            }
        }
        if ($this->tableExists('archive_manifests')) {
            foreach ($this->connection->createQueryBuilder()
                ->select('partitions_json')
                ->from('archive_manifests')
                ->where('status = :status')
                ->setParameter('status', 'completed')
                ->fetchFirstColumn() as $json) {
                $partitions = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($partitions)) {
                    continue;
                }
                foreach ($partitions as $partition) {
                    $key = is_array($partition) ? ($partition['object_key'] ?? null) : null;
                    if (is_scalar($key) && trim((string) $key) !== '') {
                        $keys[] = trim((string) $key);
                    }
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    private function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }
}
