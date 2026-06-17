<?php

declare(strict_types=1);

namespace VertoAD\Tests\Campaigns;

use Doctrine\DBAL\Connection;
use VertoAD\Tests\Review\ReviewSchema;

final class CampaignSchema
{
    public static function create(Connection $connection): void
    {
        ReviewSchema::create($connection);
        $connection->executeStatement(
            <<<'SQL'
            CREATE TABLE campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'paused', 'archived')),
                pause_reason TEXT NULL,
                pricing_model TEXT NOT NULL,
                bid_points INTEGER NOT NULL,
                landing_url TEXT NOT NULL,
                creative_asset_id INTEGER NOT NULL,
                starts_at TEXT NULL,
                ends_at TEXT NULL,
                targeting_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
        );
        $connection->executeStatement(
            'CREATE TABLE campaign_budget_caps (
                campaign_id INTEGER PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                total_cap_points INTEGER NULL,
                daily_cap_points INTEGER NULL,
                hourly_cap_points INTEGER NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE campaign_serving_frequency_caps (
                campaign_id INTEGER PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                hourly_impression_cap INTEGER NULL,
                daily_impression_cap INTEGER NULL,
                hourly_click_cap INTEGER NULL,
                daily_click_cap INTEGER NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE report_aggregates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                granularity VARCHAR(12) NOT NULL,
                bucket_start DATETIME NOT NULL,
                dimension_key VARCHAR(64) NOT NULL,
                organization_role VARCHAR(16) NOT NULL DEFAULT "platform",
                organization_id INTEGER NULL,
                campaign_id INTEGER NULL,
                site_id INTEGER NOT NULL,
                slot_id INTEGER NOT NULL,
                geo VARCHAR(120) NULL,
                device VARCHAR(120) NULL,
                browser VARCHAR(120) NULL,
                resolution VARCHAR(120) NULL,
                risk_bucket VARCHAR(120) NULL,
                impressions INTEGER NOT NULL,
                clicks INTEGER NOT NULL,
                spend_points INTEGER NOT NULL,
                revenue_points INTEGER NOT NULL,
                conversions INTEGER NOT NULL DEFAULT 0,
                conversion_value_points INTEGER NOT NULL DEFAULT 0,
                refreshed_at DATETIME NOT NULL,
                UNIQUE (granularity, bucket_start, dimension_key)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE spend_reservations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_id VARCHAR(160) NOT NULL UNIQUE,
                organization_id INTEGER NOT NULL,
                campaign_id INTEGER NOT NULL,
                points_amount INTEGER NOT NULL,
                status VARCHAR(32) NOT NULL,
                reserved_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                committed_at DATETIME NULL,
                released_at DATETIME NULL,
                expired_at DATETIME NULL,
                ledger_entry_id INTEGER NULL
            )',
        );
    }
}
