<?php

declare(strict_types=1);

namespace VertoAD\Tests\Review;

use Doctrine\DBAL\Connection;
use VertoAD\Tests\Assets\AssetSchema;

final class ReviewSchema
{
    public static function create(Connection $connection): void
    {
        AssetSchema::create($connection);
        $connection->executeStatement(
            'CREATE TABLE creative_reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_id INTEGER NOT NULL UNIQUE,
                organization_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                ai_provider TEXT NULL,
                ai_model TEXT NULL,
                ai_risk_score REAL NULL,
                ai_risk_labels TEXT NOT NULL DEFAULT "[]",
                ai_reasons TEXT NOT NULL DEFAULT "[]",
                ai_raw_result TEXT NULL,
                requested_by_user_id INTEGER NOT NULL,
                final_decision TEXT NULL,
                final_decision_reason TEXT NULL,
                final_decided_by_user_id INTEGER NULL,
                final_decided_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE review_decisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                review_id INTEGER NOT NULL,
                asset_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                actor_user_id INTEGER NOT NULL,
                decision TEXT NOT NULL,
                reason TEXT NULL,
                from_status TEXT NOT NULL,
                to_status TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE review_eligibility_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                review_id INTEGER NOT NULL,
                asset_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                eligibility TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }
}
