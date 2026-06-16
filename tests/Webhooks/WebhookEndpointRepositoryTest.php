<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipher;

final class WebhookEndpointRepositoryTest extends TestCase
{
    public function testStoresListsUpdatesAndRotatesEndpointSecretsWithoutHashOnlyStorage(): void
    {
        $connection = $this->createConnection();
        $cipher = new WebhookEndpointSecretCipher(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            static fn (): string => 'whsec_repository_secret_v1',
        );
        $repository = new DatabaseWebhookEndpointRepository($connection);
        $secret = $cipher->generateSigningSecret();

        $stored = $repository->store(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_repository_1',
            organizationId: 99,
            createdByUserId: 7,
            name: '  Review approvals  ',
            endpointUrl: '  https://hooks.example/vertoad  ',
            status: 'active',
            events: [' review.approved ', 'billing.points_changed', 'review.approved'],
            encryptedSigningSecret: $cipher->encrypt($secret),
            secretPreview: $cipher->preview($secret),
            secretRotatedAt: new DateTimeImmutable('2026-06-10T01:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-06-10T01:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-10T01:00:00+00:00'),
        ));

        self::assertNotNull($stored->id);
        self::assertSame('Review approvals', $stored->name);
        self::assertSame('https://hooks.example/vertoad', $stored->endpointUrl);
        self::assertSame(['billing.points_changed', 'review.approved'], $stored->events);
        self::assertSame('whsec_repository_secret_v1', $cipher->decrypt($stored->encryptedSigningSecret));
        self::assertNotSame('whsec_repository_secret_v1', $connection->fetchOne('SELECT encrypted_signing_secret FROM webhook_endpoints'));
        self::assertStringContainsString('...', $stored->secretPreview);

        $fresh = new DatabaseWebhookEndpointRepository($connection);
        self::assertSame(['whe_repository_1'], array_map(
            static fn (WebhookEndpoint $endpoint): string => $endpoint->endpointId,
            $fresh->listForOrganization(99),
        ));
        self::assertSame([], $fresh->listForOrganization(100));

        $updated = $fresh->update($stored->withChanges(
            name: 'Billing and review',
            endpointUrl: 'https://hooks.example/updated',
            status: 'paused',
            events: ['campaign.status_changed'],
        ));

        self::assertSame('paused', $updated->status);
        self::assertFalse($updated->enabled());
        self::assertSame(['campaign.status_changed'], $updated->events);
        self::assertSame(['campaign.status_changed'], $this->eventsFor($connection, (int) $stored->id));

        $rotatedSecret = 'whsec_repository_secret_v2';
        $rotated = $fresh->rotateSecret(
            endpointId: 'whe_repository_1',
            organizationId: 99,
            encryptedSigningSecret: $cipher->encrypt($rotatedSecret),
            secretPreview: $cipher->preview($rotatedSecret),
            secretRotatedAt: new DateTimeImmutable('2026-06-10T02:00:00+00:00'),
        );

        self::assertNotNull($rotated);
        self::assertSame('whsec_repository_secret_v2', $cipher->decrypt($rotated->encryptedSigningSecret));
        self::assertNull($fresh->rotateSecret(
            endpointId: 'whe_repository_1',
            organizationId: 100,
            encryptedSigningSecret: $cipher->encrypt('whsec_wrong_org'),
            secretPreview: 'whsec_...',
            secretRotatedAt: new DateTimeImmutable('2026-06-10T03:00:00+00:00'),
        ));
    }

    public function testEndpointValidationRejectsUnsafeUrlsAndInvalidEventLists(): void
    {
        $ciphertext = 'defuse:v1:encrypted';
        $time = new DateTimeImmutable('2026-06-10T01:00:00+00:00');

        foreach ([
            '',
            'not a url',
            'http://hooks.example/vertoad',
            'https://localhost/vertoad',
            'https://api.localhost/vertoad',
            'https://127.0.0.1/vertoad',
            'https://10.0.0.1/vertoad',
            'https://169.254.10.20/vertoad',
            'https://[::1]/vertoad',
            'https://999.999.999.999/vertoad',
            'https://bad_host!/vertoad',
        ] as $unsafeUrl) {
            try {
                new WebhookEndpoint(
                    id: null,
                    endpointId: 'whe_unsafe',
                    organizationId: 99,
                    createdByUserId: 7,
                    name: 'Unsafe',
                    endpointUrl: $unsafeUrl,
                    status: 'active',
                    events: ['review.approved'],
                    encryptedSigningSecret: $ciphertext,
                    secretPreview: 'whsec_...',
                    secretRotatedAt: $time,
                    createdAt: $time,
                    updatedAt: $time,
                );
                self::fail('Unsafe webhook endpoint URL should be rejected: ' . $unsafeUrl);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Webhook endpoint URL', $exception->getMessage());
            }
        }

        $publicIpEndpoint = new WebhookEndpoint(
            id: null,
            endpointId: 'whe_public_ip',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Public IP',
            endpointUrl: 'https://8.8.8.8/vertoad',
            status: 'active',
            events: ['review.approved'],
            encryptedSigningSecret: $ciphertext,
            secretPreview: 'whsec_...',
            secretRotatedAt: $time,
            createdAt: $time,
            updatedAt: $time,
        );
        self::assertSame('https://8.8.8.8/vertoad', $publicIpEndpoint->endpointUrl);
        self::assertSame(['review.approved'], WebhookEndpoint::normalizeEvents(['', ' review.approved ', 'review.approved']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook endpoint event type format is invalid.');
        WebhookEndpoint::normalizeEvents(['Review.Approved']);
    }

    public function testEndpointValidationRejectsInvalidIdentityAndSecretMetadata(): void
    {
        $time = new DateTimeImmutable('2026-06-10T01:00:00+00:00');

        foreach ([
            ['id' => 0, 'message' => 'Webhook endpoint internal ID must be positive when provided.'],
            ['endpointId' => ' ', 'message' => 'Webhook endpoint ID is required.'],
            ['organizationId' => 0, 'message' => 'Webhook endpoint organization ID must be positive.'],
            ['createdByUserId' => 0, 'message' => 'Webhook endpoint creator user ID must be positive.'],
            ['name' => ' ', 'message' => 'Webhook endpoint name is required.'],
            ['status' => 'deleted', 'message' => 'Webhook endpoint status is not supported.'],
            ['encryptedSigningSecret' => ' ', 'message' => 'Webhook endpoint encrypted signing secret is required.'],
            ['secretPreview' => ' ', 'message' => 'Webhook endpoint secret preview is required.'],
        ] as $case) {
            try {
                new WebhookEndpoint(
                    id: $case['id'] ?? null,
                    endpointId: $case['endpointId'] ?? 'whe_valid',
                    organizationId: $case['organizationId'] ?? 99,
                    createdByUserId: $case['createdByUserId'] ?? 7,
                    name: $case['name'] ?? 'Valid endpoint',
                    endpointUrl: 'https://hooks.example/vertoad',
                    status: $case['status'] ?? 'active',
                    events: ['review.approved'],
                    encryptedSigningSecret: $case['encryptedSigningSecret'] ?? 'defuse:v1:encrypted',
                    secretPreview: $case['secretPreview'] ?? 'whsec_...',
                    secretRotatedAt: $time,
                    createdAt: $time,
                    updatedAt: $time,
                );
                self::fail('Expected invalid webhook endpoint metadata to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($case['message'], $exception->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook endpoint requires at least one event.');

        new WebhookEndpoint(
            id: null,
            endpointId: 'whe_no_events',
            organizationId: 99,
            createdByUserId: 7,
            name: 'No events',
            endpointUrl: 'https://hooks.example/vertoad',
            status: 'active',
            events: [],
            encryptedSigningSecret: 'defuse:v1:encrypted',
            secretPreview: 'whsec_...',
            secretRotatedAt: $time,
            createdAt: $time,
            updatedAt: $time,
        );
    }

    public function testEndpointRepositoryBoundaryInputsReturnNullOrRejectMissingInternalId(): void
    {
        $repository = new DatabaseWebhookEndpointRepository($this->createConnection());
        $time = new DateTimeImmutable('2026-06-10T01:00:00+00:00');

        self::assertNull($repository->findById(0));
        self::assertNull($repository->findForOrganization('', 99));
        self::assertNull($repository->findForOrganization('whe_missing', 0));
        self::assertSame([], $repository->listForOrganization(0));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook endpoint internal ID is required for update.');

        $repository->update(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_missing_id',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Missing ID',
            endpointUrl: 'https://hooks.example/missing-id',
            status: 'active',
            events: ['review.approved'],
            encryptedSigningSecret: 'defuse:v1:encrypted',
            secretPreview: 'whsec_...',
            secretRotatedAt: $time,
            createdAt: $time,
            updatedAt: $time,
        ));
    }

    public function testQueuesEndpointScopedDeliveriesAndDurableAttempts(): void
    {
        $connection = $this->createConnection();
        $endpointRepository = new DatabaseWebhookEndpointRepository($connection);
        $deliveryRepository = new DatabaseWebhookDeliveryRepository($connection);
        $time = new DateTimeImmutable('2026-06-10T01:00:00+00:00');
        $endpoint = $endpointRepository->store(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_delivery_1',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Delivery endpoint',
            endpointUrl: 'https://hooks.example/deliveries',
            status: 'active',
            events: ['webhook.test'],
            encryptedSigningSecret: 'defuse:v1:encrypted',
            secretPreview: 'whsec_...',
            secretRotatedAt: $time,
            createdAt: $time,
            updatedAt: $time,
        ));

        $delivery = $deliveryRepository->queueForEndpoint($endpoint, 'webhook.test', ['endpoint_id' => $endpoint->endpointId]);

        self::assertSame(99, $delivery->organization_id);
        self::assertSame($endpoint->id, $delivery->webhook_endpoint_id);
        self::assertSame($endpoint->endpointId, $delivery->endpoint_id);
        self::assertSame('https://hooks.example/deliveries', $delivery->endpoint_url);
        self::assertSame('queued', $delivery->status);
        self::assertNotNull($delivery->next_attempt_at);
        self::assertNull($delivery->last_attempt_at);
        self::assertSame([$delivery->delivery_id], array_map(
            static fn ($queued): string => $queued->delivery_id,
            $deliveryRepository->listForOrganization(99, endpointId: 'whe_delivery_1', status: 'queued', limit: 10),
        ));

        $deliveryRepository->recordAttempt(
            deliveryId: $delivery->delivery_id,
            attemptNumber: 1,
            statusCode: 202,
            error: null,
            signatureHeader: 't=1,v1=' . str_repeat('a', 64),
            attemptedAt: new DateTimeImmutable('2026-06-10T01:01:00+00:00'),
            durationMs: 123,
        );

        $attempts = $deliveryRepository->attemptsForDelivery($delivery->delivery_id);
        self::assertCount(1, $attempts);
        self::assertSame(1, $attempts[0]['attempt_number']);
        self::assertSame(202, $attempts[0]['status_code']);
        self::assertSame(123, $attempts[0]['duration_ms']);
    }

    /**
     * @return list<string>
     */
    private function eventsFor(Connection $connection, int $endpointInternalId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['event_type'],
            $connection->createQueryBuilder()
                ->select('event_type')
                ->from('webhook_endpoint_events')
                ->where('webhook_endpoint_id = :id')
                ->orderBy('event_type', 'ASC')
                ->setParameter('id', $endpointInternalId)
                ->fetchAllAssociative(),
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE webhook_endpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                endpoint_id VARCHAR(160) NOT NULL UNIQUE,
                organization_id INTEGER NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                name VARCHAR(160) NOT NULL,
                endpoint_url TEXT NOT NULL,
                status VARCHAR(32) NOT NULL,
                encrypted_signing_secret TEXT NOT NULL,
                secret_preview VARCHAR(64) NOT NULL,
                secret_rotated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE webhook_endpoint_events (
                webhook_endpoint_id INTEGER NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                PRIMARY KEY (webhook_endpoint_id, event_type)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE webhook_deliveries (
                delivery_id VARCHAR(160) PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                webhook_endpoint_id INTEGER NOT NULL,
                endpoint_url TEXT NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                payload_json TEXT NOT NULL,
                request_id TEXT NULL,
                status VARCHAR(32) NOT NULL,
                retry_count INTEGER NOT NULL,
                next_attempt_at DATETIME NOT NULL,
                last_attempt_at DATETIME NULL,
                last_status_code INTEGER NULL,
                last_error TEXT NULL,
                signature_header TEXT NULL,
                created_at DATETIME NOT NULL,
                delivered_at DATETIME NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE webhook_delivery_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                delivery_id VARCHAR(160) NOT NULL,
                attempt_number INTEGER NOT NULL,
                status_code INTEGER NULL,
                error TEXT NULL,
                signature_header TEXT NULL,
                attempted_at DATETIME NOT NULL,
                duration_ms INTEGER NOT NULL
            )',
        );

        return $connection;
    }
}
