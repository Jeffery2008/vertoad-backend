<?php

declare(strict_types=1);

namespace VertoAD\Service\Partition;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final class EventTablePartitionMaintainer
{
    /**
     * @var list<string>
     */
    private const TABLES = ['raw_events', 'ad_serving_events'];

    private readonly DateTimeImmutable $now;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $lookaheadMonths,
        ?DateTimeImmutable $now = null,
    ) {
        if ($lookaheadMonths <= 0) {
            throw new \InvalidArgumentException('Partition maintenance lookahead months must be positive.');
        }

        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, int|string|null>
     */
    public function maintain(): array
    {
        $metrics = [
            'tables_checked' => 0,
            'partitions_created' => 0,
            'tables_reorganized' => 0,
            'tables_already_current' => 0,
            'protected_tables' => 0,
        ];

        foreach (self::TABLES as $table) {
            ++$metrics['tables_checked'];

            $existingPartitions = $this->existingPartitions($table);
            $missingPartitions = $this->missingPartitions($existingPartitions);
            $futureRows = (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM `%s` PARTITION (p_future)', $table),
            );
            if ($futureRows > 0) {
                ++$metrics['protected_tables'];
                $metrics['protected_table'] ??= $table;
                $metrics['future_rows'] ??= $futureRows;

                continue;
            }

            if ($missingPartitions === []) {
                ++$metrics['tables_already_current'];

                continue;
            }

            $this->connection->executeStatement(
                $this->reorganizeSql($table, $missingPartitions),
            );
            ++$metrics['tables_reorganized'];
            $metrics['partitions_created'] += count($missingPartitions);
        }

        return $metrics;
    }

    /**
     * @return list<string>
     */
    private function existingPartitions(string $table): array
    {
        $partitions = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT partition_name
FROM information_schema.partitions
WHERE table_schema = DATABASE()
  AND table_name = :table_name
  AND partition_name IS NOT NULL
ORDER BY partition_ordinal_position ASC
SQL,
            ['table_name' => $table],
        );

        return array_values(array_map(
            static fn (mixed $partition): string => trim((string) $partition),
            is_array($partitions) ? $partitions : [],
        ));
    }

    /**
     * @param list<string> $existingPartitions
     * @return list<array{name: string, upper_bound: string}>
     */
    private function missingPartitions(array $existingPartitions): array
    {
        $existing = array_fill_keys($existingPartitions, true);
        $missing = [];
        $currentMonthStart = $this->now->modify('first day of this month')->setTime(0, 0, 0);

        for ($offset = 1; $offset <= $this->lookaheadMonths; ++$offset) {
            $partitionMonth = $currentMonthStart->modify('+' . $offset . ' months');
            $partitionName = $this->partitionName($partitionMonth);
            if (isset($existing[$partitionName])) {
                continue;
            }

            $missing[] = [
                'name' => $partitionName,
                'upper_bound' => $this->partitionUpperBound($partitionMonth),
            ];
        }

        return $missing;
    }

    /**
     * @param list<array{name: string, upper_bound: string}> $missingPartitions
     */
    private function reorganizeSql(string $table, array $missingPartitions): string
    {
        $parts = array_map(
            static fn (array $partition): string => sprintf(
                'PARTITION %s VALUES LESS THAN (\'%s\')',
                $partition['name'],
                $partition['upper_bound'],
            ),
            $missingPartitions,
        );
        $parts[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';

        return sprintf(
            'ALTER TABLE %s REORGANIZE PARTITION p_future INTO (%s)',
            $table,
            implode(', ', $parts),
        );
    }

    private function partitionName(DateTimeImmutable $monthStart): string
    {
        return 'p' . $monthStart->format('Ym');
    }

    private function partitionUpperBound(DateTimeImmutable $monthStart): string
    {
        return $monthStart->modify('+1 month')->format('Y-m-d 00:00:00');
    }
}
