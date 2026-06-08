<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExpandCampaignsForManagement extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE campaigns
    ADD COLUMN pricing_model VARCHAR(16) NOT NULL DEFAULT 'cpm' AFTER objective,
    ADD COLUMN bid_points BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER pricing_model,
    ADD COLUMN landing_url VARCHAR(1024) NOT NULL DEFAULT '' AFTER bid_points,
    ADD COLUMN creative_asset_id BIGINT UNSIGNED NULL AFTER landing_url,
    ADD COLUMN targeting_json JSON NULL AFTER ends_at
SQL);

        $this->execute(<<<'SQL'
UPDATE campaigns
SET
    pricing_model = CASE WHEN bid_cpm IS NULL THEN 'cpm' ELSE 'cpm' END,
    bid_points = GREATEST(1, COALESCE(CAST(ROUND(bid_cpm * 100) AS UNSIGNED), 1)),
    targeting_json = JSON_OBJECT(
        'devices', JSON_ARRAY(),
        'geos', JSON_ARRAY(),
        'site_ids', JSON_ARRAY(),
        'slot_ids', JSON_ARRAY(),
        'time_windows', JSON_ARRAY()
    )
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE campaigns
    MODIFY targeting_json JSON NOT NULL,
    ADD KEY idx_campaigns_creative_asset (creative_asset_id),
    ADD CONSTRAINT fk_campaigns_creative_asset FOREIGN KEY (creative_asset_id) REFERENCES creative_assets (id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_campaigns_pricing_model CHECK (pricing_model IN ('cpm', 'cpc')),
    ADD CONSTRAINT chk_campaigns_bid_points_positive CHECK (bid_points > 0)
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE campaigns
    DROP FOREIGN KEY fk_campaigns_creative_asset,
    DROP CHECK chk_campaigns_pricing_model,
    DROP CHECK chk_campaigns_bid_points_positive
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE campaigns
    DROP INDEX idx_campaigns_creative_asset,
    DROP COLUMN pricing_model,
    DROP COLUMN bid_points,
    DROP COLUMN landing_url,
    DROP COLUMN creative_asset_id,
    DROP COLUMN targeting_json
SQL);
    }
}
