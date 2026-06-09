<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use PHPUnit\Framework\TestCase;

final class WebhookEndpointMigrationTest extends TestCase
{
    public function testEndpointMigrationDefinesEncryptedEndpointSecretsAndEventPairs(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260609005000_create_webhook_endpoint_tables.php';
        self::assertFileExists($path);

        $sql = $this->normalizedSql($path);
        foreach ([
            'create table webhook_endpoints',
            'id bigint unsigned not null auto_increment',
            'endpoint_id varchar(160) not null',
            'organization_id bigint unsigned not null',
            'created_by_user_id bigint unsigned not null',
            'name varchar(160) not null',
            'endpoint_url varchar(2048) not null',
            'status varchar(32) not null',
            'encrypted_signing_secret text not null',
            'secret_preview varchar(64) not null',
            'secret_rotated_at datetime(6) not null',
            'unique key uq_webhook_endpoints_endpoint_id',
            'constraint chk_webhook_endpoints_status check (status in (\'active\', \'paused\'))',
            'constraint fk_webhook_endpoints_organization foreign key (organization_id) references organizations (id) on delete cascade',
            'constraint fk_webhook_endpoints_created_by foreign key (created_by_user_id) references users (id) on delete restrict',
            'create table webhook_endpoint_events',
            'webhook_endpoint_id bigint unsigned not null',
            'event_type varchar(120) not null',
            'primary key (webhook_endpoint_id, event_type)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }

        self::assertStringNotContainsString('unique key uq_webhook_endpoint_events_pair', $sql);
        self::assertStringNotContainsString('secret_hash', $sql);
        self::assertStringNotContainsString('webhook_subscriptions', $sql);
    }

    public function testDeliveryMigrationDefinesEndpointScopedDeliveriesAndAttemptLog(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260609010000_create_webhook_delivery_tables.php';
        self::assertFileExists($path);

        $sql = $this->normalizedSql($path);
        foreach ([
            'create table webhook_deliveries',
            'delivery_id varchar(160) not null',
            'organization_id bigint unsigned not null',
            'webhook_endpoint_id bigint unsigned not null',
            'endpoint_url varchar(2048) not null',
            'event_type varchar(120) not null',
            'payload_json json not null',
            'status varchar(32) not null',
            'retry_count int unsigned not null',
            'next_attempt_at datetime(6) not null',
            'last_attempt_at datetime(6) null',
            'last_status_code int unsigned null',
            'last_error varchar(255) null',
            'signature_header varchar(255) null',
            'delivered_at datetime(6) null',
            'idx_webhook_deliveries_org_endpoint_status',
            'constraint chk_webhook_deliveries_status check (status in (\'queued\', \'delivered\', \'failed\'))',
            'constraint chk_webhook_deliveries_last_status_code check (last_status_code is null or last_status_code between 100 and 599)',
            'constraint fk_webhook_deliveries_organization foreign key (organization_id) references organizations (id) on delete cascade',
            'create table webhook_delivery_attempts',
            'attempt_number int unsigned not null',
            'duration_ms int unsigned not null',
            'unique key uq_webhook_delivery_attempts_delivery_attempt (delivery_id, attempt_number)',
            'constraint chk_webhook_delivery_attempts_attempt_positive check (attempt_number > 0)',
            'constraint chk_webhook_delivery_attempts_status_code check (status_code is null or status_code between 100 and 599)',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }

        self::assertStringNotContainsString('subscription_id', $sql);
        self::assertStringNotContainsString('webhook_subscriptions', $sql);
    }

    private function normalizedSql(string $path): string
    {
        return preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
    }
}
