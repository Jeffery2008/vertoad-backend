<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use PHPUnit\Framework\TestCase;

final class RawEventSchemaMigrationTest extends TestCase
{
    public function testRawEventUuidMatchesServingEventIdLength(): void
    {
        $migration = strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260606134000_create_core_schema.php'));

        self::assertStringContainsString('event_uuid varchar(255) not null', $migration);
        self::assertStringNotContainsString('event_uuid char(36)', $migration);
        self::assertStringContainsString('unique key uq_raw_events_event_uuid_occurred (event_uuid, occurred_at)', $migration);
        self::assertStringContainsString('key idx_raw_events_event_uuid (event_uuid)', $migration);
    }

    public function testRawEventsArePartitionedByOccurredAtForMySqlHotStorage(): void
    {
        $migrationSql = $this->partitionMigrationSql();

        foreach ([
            'alter table raw_events',
            'drop foreign key fk_raw_events_organization',
            'drop foreign key fk_raw_events_site',
            'drop foreign key fk_raw_events_ad_slot',
            'drop foreign key fk_raw_events_campaign',
            'drop foreign key fk_raw_events_creative',
            'drop primary key',
            'add primary key (id, occurred_at)',
            'partition by range columns (occurred_at)',
            "partition p_before_2026 values less than ('2026-01-01 00:00:00')",
            "partition p202606 values less than ('2026-07-01 00:00:00')",
            'partition p_future values less than (maxvalue)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $migrationSql);
        }

        self::assertStringNotContainsString('add constraint fk_raw_events', $migrationSql);
    }

    public function testRawEventDedupTableKeepsGlobalUuidUniquenessOutsidePartitionedHotTable(): void
    {
        $migrationSql = preg_replace(
            '/\s+/',
            ' ',
            strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260606134000_create_core_schema.php')),
        ) ?? '';

        foreach ([
            'create table raw_event_dedup',
            'event_uuid varchar(255) not null',
            'occurred_at datetime not null',
            'created_at datetime not null',
            'primary key (event_uuid)',
            'key idx_raw_event_dedup_occurred_at (occurred_at)',
            'unique key uq_raw_events_event_uuid_occurred (event_uuid, occurred_at)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $migrationSql);
        }

        self::assertStringContainsString('dedup', $migrationSql);
        self::assertStringNotContainsString('add unique key uq_raw_events_event_uuid (event_uuid)', $migrationSql);
    }

    public function testPartitionMigrationDownDocumentsIrreversibleKeyChange(): void
    {
        $migrationSql = $this->partitionMigrationSql();

        self::assertStringContainsString('public function down(): void', $migrationSql);
        self::assertStringContainsString('irreversiblemigrationexception', $migrationSql);
        self::assertStringContainsString('global event identity', $migrationSql);
    }

    private function partitionMigrationSql(): string
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260615160000_partition_event_tables.php';
        self::assertFileExists($path);

        return preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
    }
}
