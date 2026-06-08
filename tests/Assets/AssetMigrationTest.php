<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class AssetMigrationTest extends TestCase
{
    private string $migrationSql;

    protected function setUp(): void
    {
        $migrationPath = dirname(__DIR__, 2) . '/db/migrations/20260608010000_create_asset_upload_tables.php';

        self::assertFileExists($migrationPath);

        $this->migrationSql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($migrationPath))) ?? '';
    }

    public function testAssetUploadTablesAreCreated(): void
    {
        foreach ([
            'create table asset_upload_intents',
            'create table creative_assets',
            'create table asset_snapshot_jobs',
        ] as $tableSql) {
            self::assertStringContainsString($tableSql, $this->migrationSql);
        }
    }

    public function testAssetColumnsAndIndexesArePresent(): void
    {
        foreach ([
            'organization_id bigint unsigned not null',
            'uploader_user_id bigint unsigned not null',
            'object_key varchar(512) not null',
            'content_type varchar(120) not null',
            'byte_size bigint unsigned not null',
            'width int unsigned not null',
            'height int unsigned not null',
            'duration_seconds decimal(10,3) null',
            'checksum varchar(160) null',
            'status varchar(32) not null',
            'unique key uq_asset_upload_intents_object_key (object_key)',
            'unique key uq_creative_assets_upload_intent (upload_intent_id)',
            'unique key uq_creative_assets_object_key (object_key)',
            'key idx_asset_snapshot_jobs_status_created (status, created_at)',
        ] as $sql) {
            self::assertStringContainsString($sql, $this->migrationSql);
        }
    }
}
