<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use Doctrine\DBAL\Connection;

final class CreativeSchema
{
    public static function create(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE creative_templates (
                template_id TEXT PRIMARY KEY,
                organization_id INTEGER NULL,
                name TEXT NOT NULL,
                description TEXT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                fabric_json TEXT NOT NULL,
                snapshot_url TEXT NULL,
                tags_json TEXT NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE creative_designs (
                design_id TEXT PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                current_version INTEGER NOT NULL,
                template_id TEXT NULL,
                status TEXT NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                updated_by_user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE creative_design_versions (
                version_id TEXT PRIMARY KEY,
                design_id TEXT NOT NULL,
                version_number INTEGER NOT NULL,
                fabric_json TEXT NOT NULL,
                snapshot_url TEXT NULL,
                change_summary TEXT NULL,
                created_by_user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (design_id, version_number)
            )',
        );
    }
}
