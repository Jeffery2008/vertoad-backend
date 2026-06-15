<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PartitionEventTables extends AbstractMigration
{
    public function up(): void
    {
        // MySQL partitioned InnoDB tables cannot keep foreign keys, and every
        // unique key must include the partitioning column.
        $this->execute(<<<'SQL'
ALTER TABLE raw_events
    DROP FOREIGN KEY fk_raw_events_organization,
    DROP FOREIGN KEY fk_raw_events_site,
    DROP FOREIGN KEY fk_raw_events_ad_slot,
    DROP FOREIGN KEY fk_raw_events_campaign,
    DROP FOREIGN KEY fk_raw_events_creative,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (id, occurred_at)
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE raw_events
    PARTITION BY RANGE COLUMNS (occurred_at) (
        PARTITION p_before_2026 VALUES LESS THAN ('2026-01-01 00:00:00'),
        PARTITION p202601 VALUES LESS THAN ('2026-02-01 00:00:00'),
        PARTITION p202602 VALUES LESS THAN ('2026-03-01 00:00:00'),
        PARTITION p202603 VALUES LESS THAN ('2026-04-01 00:00:00'),
        PARTITION p202604 VALUES LESS THAN ('2026-05-01 00:00:00'),
        PARTITION p202605 VALUES LESS THAN ('2026-06-01 00:00:00'),
        PARTITION p202606 VALUES LESS THAN ('2026-07-01 00:00:00'),
        PARTITION p202607 VALUES LESS THAN ('2026-08-01 00:00:00'),
        PARTITION p202608 VALUES LESS THAN ('2026-09-01 00:00:00'),
        PARTITION p202609 VALUES LESS THAN ('2026-10-01 00:00:00'),
        PARTITION p202610 VALUES LESS THAN ('2026-11-01 00:00:00'),
        PARTITION p202611 VALUES LESS THAN ('2026-12-01 00:00:00'),
        PARTITION p202612 VALUES LESS THAN ('2027-01-01 00:00:00'),
        PARTITION p_future VALUES LESS THAN (MAXVALUE)
    )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE ad_serving_events
    DROP FOREIGN KEY fk_ad_serving_events_decision,
    DROP FOREIGN KEY fk_ad_serving_events_site,
    DROP FOREIGN KEY fk_ad_serving_events_slot,
    DROP FOREIGN KEY fk_ad_serving_events_campaign,
    DROP FOREIGN KEY fk_ad_serving_events_advertiser_org,
    DROP FOREIGN KEY fk_ad_serving_events_publisher_org,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (id, occurred_at)
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE ad_serving_events
    PARTITION BY RANGE COLUMNS (occurred_at) (
        PARTITION p_before_2026 VALUES LESS THAN ('2026-01-01 00:00:00'),
        PARTITION p202601 VALUES LESS THAN ('2026-02-01 00:00:00'),
        PARTITION p202602 VALUES LESS THAN ('2026-03-01 00:00:00'),
        PARTITION p202603 VALUES LESS THAN ('2026-04-01 00:00:00'),
        PARTITION p202604 VALUES LESS THAN ('2026-05-01 00:00:00'),
        PARTITION p202605 VALUES LESS THAN ('2026-06-01 00:00:00'),
        PARTITION p202606 VALUES LESS THAN ('2026-07-01 00:00:00'),
        PARTITION p202607 VALUES LESS THAN ('2026-08-01 00:00:00'),
        PARTITION p202608 VALUES LESS THAN ('2026-09-01 00:00:00'),
        PARTITION p202609 VALUES LESS THAN ('2026-10-01 00:00:00'),
        PARTITION p202610 VALUES LESS THAN ('2026-11-01 00:00:00'),
        PARTITION p202611 VALUES LESS THAN ('2026-12-01 00:00:00'),
        PARTITION p202612 VALUES LESS THAN ('2027-01-01 00:00:00'),
        PARTITION p_future VALUES LESS THAN (MAXVALUE)
    )
SQL);
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException(
            'PartitionEventTables is irreversible: MySQL partitioning requires occurred_at in unique keys, removes event-table foreign keys, and introduces separate dedup tables for global event identity; reverting would falsely imply those guarantees can be restored safely.',
        );
    }
}
