<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;

final class DatabaseWebhookDeliveryRepositoryTest extends TestCase
{
    public function testQueuedDeliveriesSurviveRepositoryInstancesAndSupportRetryLifecycle(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseWebhookDeliveryRepository($connection);

        $first = $repository->queue('https://hooks.example/first', 'review.approved', ['review_id' => 10]);
        $second = $repository->queue('https://hooks.example/second', 'billing.points_changed', ['ledger_id' => 20]);

        $fresh = new DatabaseWebhookDeliveryRepository($connection);

        self::assertSame($first->delivery_id, $fresh->find($first->delivery_id)?->delivery_id);
        self::assertSame('{"review_id":10}', $fresh->find($first->delivery_id)?->payload_json);
        self::assertSame([$first->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $fresh->pendingRetry(1),
        ));

        $delivered = new WebhookDelivery(
            delivery_id: $first->delivery_id,
            endpoint_url: $first->endpoint_url,
            event_type: $first->event_type,
            payload_json: $first->payload_json,
            status: 'delivered',
            retry_count: 1,
            last_error: null,
            signature_header: 'v1=signature',
            created_at: $first->created_at,
            delivered_at: new DateTimeImmutable('2026-06-09T02:00:00+00:00'),
        );
        $fresh->save($delivered);

        $pending = $fresh->pendingRetry(10);
        self::assertSame([$second->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $pending,
        ));

        $stored = (new DatabaseWebhookDeliveryRepository($connection))->find($first->delivery_id);
        self::assertNotNull($stored);
        self::assertSame('delivered', $stored->status);
        self::assertSame(1, $stored->retry_count);
        self::assertNull($stored->last_error);
        self::assertSame('v1=signature', $stored->signature_header);
        self::assertSame(2, count($fresh->all()));
    }

    public function testPendingRetryRejectsNonPositiveLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry batch size must be positive.');

        (new DatabaseWebhookDeliveryRepository($this->createConnection()))->pendingRetry(0);
    }

    public function testWebhookMigrationDefinesDurableDeliveryLog(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260609010000_create_webhook_delivery_tables.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table webhook_deliveries',
            'delivery_id varchar(160) not null',
            'endpoint_url varchar(2048) not null',
            'payload_json json not null',
            'status varchar(32) not null',
            'retry_count int unsigned not null',
            'last_error varchar(255) null',
            'signature_header varchar(255) null',
            'delivered_at datetime null',
            'idx_webhook_deliveries_retry',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE webhook_deliveries (
                delivery_id VARCHAR(160) PRIMARY KEY,
                endpoint_url TEXT NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                payload_json TEXT NOT NULL,
                status VARCHAR(32) NOT NULL,
                retry_count INTEGER NOT NULL,
                last_error TEXT NULL,
                signature_header TEXT NULL,
                created_at DATETIME NOT NULL,
                delivered_at DATETIME NULL
            )',
        );

        return $connection;
    }
}
