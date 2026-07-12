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
        $followUpPath = dirname(__DIR__, 2) . '/db/migrations/20260710120000_productionize_asset_snapshot_pipeline.php';

        self::assertFileExists($migrationPath);
        self::assertFileDoesNotExist($followUpPath);

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
            "snapshot_status varchar(32) not null default 'pending'",
            'snapshot_png_object_key varchar(512) null',
            'snapshot_webp_object_key varchar(512) null',
            'thumbnail_webp_object_key varchar(512) null',
            'snapshot_completed_at datetime null',
            'available_at datetime not null default current_timestamp',
            'lease_token varchar(64) null',
            'lease_expires_at datetime null',
            'last_error_code varchar(80) null',
            'last_error_message varchar(1000) null',
            'completed_at datetime null',
            'unique key uq_asset_upload_intents_object_key (object_key)',
            'unique key uq_creative_assets_upload_intent (upload_intent_id)',
            'unique key uq_creative_assets_object_key (object_key)',
            'unique key uq_creative_assets_snapshot_png_key (snapshot_png_object_key)',
            'unique key uq_creative_assets_snapshot_webp_key (snapshot_webp_object_key)',
            'unique key uq_creative_assets_thumbnail_webp_key (thumbnail_webp_object_key)',
            'unique key uq_asset_snapshot_jobs_asset (asset_id)',
            'key idx_creative_assets_snapshot_status (snapshot_status, created_at)',
            'key idx_asset_snapshot_jobs_status_created (status, created_at)',
            'key idx_asset_snapshot_jobs_claim (status, available_at, lease_expires_at, id)',
        ] as $sql) {
            self::assertStringContainsString($sql, $this->migrationSql);
        }
    }
}
