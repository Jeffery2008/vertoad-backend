<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use Doctrine\DBAL\Connection;

final class AssetSchema
{
    public static function create(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE asset_upload_intents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                uploader_user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                original_filename TEXT NOT NULL,
                object_key TEXT NOT NULL UNIQUE,
                content_type TEXT NOT NULL,
                byte_size INTEGER NOT NULL,
                status TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE creative_assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                upload_intent_id INTEGER NOT NULL UNIQUE,
                organization_id INTEGER NOT NULL,
                uploader_user_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                object_key TEXT NOT NULL UNIQUE,
                content_type TEXT NOT NULL,
                byte_size INTEGER NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                duration_seconds REAL NULL,
                checksum TEXT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE asset_snapshot_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }
}
