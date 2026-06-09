<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCampaignServingFrequencyCaps extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE campaign_serving_frequency_caps (
    campaign_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    hourly_impression_cap INT UNSIGNED NULL,
    daily_impression_cap INT UNSIGNED NULL,
    hourly_click_cap INT UNSIGNED NULL,
    daily_click_cap INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id),
    KEY idx_campaign_serving_frequency_caps_organization (organization_id),
    CONSTRAINT fk_campaign_serving_frequency_caps_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_serving_frequency_caps_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_campaign_serving_frequency_caps_hourly_impression CHECK (hourly_impression_cap IS NULL OR hourly_impression_cap > 0),
    CONSTRAINT chk_campaign_serving_frequency_caps_daily_impression CHECK (daily_impression_cap IS NULL OR daily_impression_cap > 0),
    CONSTRAINT chk_campaign_serving_frequency_caps_hourly_click CHECK (hourly_click_cap IS NULL OR hourly_click_cap > 0),
    CONSTRAINT chk_campaign_serving_frequency_caps_daily_click CHECK (daily_click_cap IS NULL OR daily_click_cap > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS campaign_serving_frequency_caps');
    }
}

