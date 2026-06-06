<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPublisherSlotSetupColumns extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE ad_slots
    ADD COLUMN size_preset VARCHAR(64) NULL AFTER height,
    ADD COLUMN is_responsive TINYINT(1) NOT NULL DEFAULT 0 AFTER size_preset,
    ADD COLUMN responsive_rules_json JSON NULL AFTER is_responsive,
    ADD KEY idx_ad_slots_size_preset (size_preset)
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE ad_slots
    DROP KEY idx_ad_slots_size_preset,
    DROP COLUMN responsive_rules_json,
    DROP COLUMN is_responsive,
    DROP COLUMN size_preset
SQL);
    }
}
