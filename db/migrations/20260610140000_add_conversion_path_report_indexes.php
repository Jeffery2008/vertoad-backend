<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddConversionPathReportIndexes extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE attribution_conversions
    ADD KEY idx_attribution_conversions_path_lookup (attributed, occurred_at, click_event_id, campaign_id)
SQL);

        $this->execute(<<<'SQL'
ALTER TABLE ad_serving_events
    ADD KEY idx_ad_serving_events_adv_path (advertiser_organization_id, viewer_id, event_type, valid, occurred_at),
    ADD KEY idx_ad_serving_events_pub_path (publisher_organization_id, viewer_id, event_type, valid, occurred_at)
SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE ad_serving_events DROP KEY idx_ad_serving_events_pub_path');
        $this->execute('ALTER TABLE ad_serving_events DROP KEY idx_ad_serving_events_adv_path');
        $this->execute('ALTER TABLE attribution_conversions DROP KEY idx_attribution_conversions_path_lookup');
    }
}
