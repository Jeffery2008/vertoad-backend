<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCampaignBudgetPrimitives extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE organization_budget_locks (
    organization_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id),
    CONSTRAINT fk_organization_budget_locks_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-advertiser budget serialization locks.'
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE campaign_budget_locks (
    organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, campaign_id),
    CONSTRAINT fk_campaign_budget_locks_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_budget_locks_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-campaign budget serialization locks.'
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE campaign_budget_caps (
    campaign_id BIGINT UNSIGNED NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    total_cap_points BIGINT UNSIGNED NULL,
    daily_cap_points BIGINT UNSIGNED NULL,
    hourly_cap_points BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (campaign_id),
    KEY idx_campaign_budget_caps_organization (organization_id),
    CONSTRAINT fk_campaign_budget_caps_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_campaign_budget_caps_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT chk_campaign_budget_caps_total_positive CHECK (total_cap_points IS NULL OR total_cap_points > 0),
    CONSTRAINT chk_campaign_budget_caps_daily_positive CHECK (daily_cap_points IS NULL OR daily_cap_points > 0),
    CONSTRAINT chk_campaign_budget_caps_hourly_positive CHECK (hourly_cap_points IS NULL OR hourly_cap_points > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE spend_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id VARCHAR(160) NOT NULL,
    organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    points_amount BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    reserved_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    committed_at DATETIME NULL,
    released_at DATETIME NULL,
    expired_at DATETIME NULL,
    ledger_entry_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_spend_reservations_reservation_id (reservation_id),
    KEY idx_spend_reservations_campaign_status_reserved (organization_id, campaign_id, status, reserved_at),
    KEY idx_spend_reservations_organization_status_expiry (organization_id, status, expires_at),
    CONSTRAINT fk_spend_reservations_organization FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE,
    CONSTRAINT fk_spend_reservations_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_spend_reservations_ledger_entry FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries (id) ON DELETE SET NULL,
    CONSTRAINT chk_spend_reservations_points_positive CHECK (points_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS spend_reservations');
        $this->execute('DROP TABLE IF EXISTS campaign_budget_caps');
        $this->execute('DROP TABLE IF EXISTS campaign_budget_locks');
        $this->execute('DROP TABLE IF EXISTS organization_budget_locks');
    }
}
