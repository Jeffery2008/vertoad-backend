<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;

final class IpGeoSchemaMigrationTest extends TestCase
{
    public function testIpGeoCanonicalStoreAndDurableQueueSchemaIsDocumentedInMigration(): void
    {
        $migration = file_get_contents(__DIR__ . '/../../db/migrations/20260618030000_create_ip_geo_store_tables.php');

        self::assertIsString($migration);
        foreach (
            [
                'CREATE TABLE ip_geo_records',
                'CREATE TABLE ip_geo_lookup_tasks',
                'CREATE TABLE ip_geo_lookup_request_ids',
                'canonical_geo_code',
                'raw_payload_summary_json JSON NOT NULL',
                'request_ids_json JSON NOT NULL',
                'lease_token VARCHAR(64) NULL',
                "CHECK (status IN ('pending', 'processing', 'failed', 'dead', 'resolved'))",
                'KEY idx_ip_geo_records_canonical_resolved (canonical_geo_code, resolved_at)',
                'KEY idx_ip_geo_tasks_status_next (status, next_attempt_at)',
                'KEY idx_ip_geo_tasks_lease_token (lease_token)',
                'KEY idx_ip_geo_tasks_request_created (request_id, created_at)',
                'KEY idx_ip_geo_lookup_request_ids_request (request_id, created_at)',
            ] as $expected
        ) {
            self::assertStringContainsString($expected, $migration);
        }

        self::assertSame(3, substr_count($migration, 'CREATE TABLE ip_geo_'));
        self::assertMatchesRegularExpression('/CREATE TABLE ip_geo_records .*PRIMARY KEY \(ip_hash\)/s', $migration);
        self::assertMatchesRegularExpression('/CREATE TABLE ip_geo_lookup_tasks .*PRIMARY KEY \(ip_hash\)/s', $migration);
        self::assertMatchesRegularExpression('/CREATE TABLE ip_geo_lookup_request_ids .*PRIMARY KEY \(ip_hash, request_id\)/s', $migration);
        self::assertMatchesRegularExpression(
            '/FOREIGN KEY \(ip_hash\) REFERENCES ip_geo_lookup_tasks \(ip_hash\) ON DELETE CASCADE/s',
            $migration,
        );
        self::assertLessThan(
            strpos($migration, 'DROP TABLE IF EXISTS ip_geo_lookup_tasks'),
            strpos($migration, 'DROP TABLE IF EXISTS ip_geo_lookup_request_ids'),
        );
        self::assertLessThan(
            strpos($migration, 'DROP TABLE IF EXISTS ip_geo_records'),
            strpos($migration, 'DROP TABLE IF EXISTS ip_geo_lookup_tasks'),
        );
    }
}
