<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Repository\Webhooks\InMemoryWebhookDeliveryRepository;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;
use VertoAD\Service\Webhooks\WebhookSigner;

final class WebhookDeliveryServiceTest extends TestCase
{
    public function testWebhookSignerUsesHmacSha256AndProducesVerifiableHeader(): void
    {
        $signer = new WebhookSigner('whsec_test_secret');
        $payload = '{"event":"operations.error.created","id":"evt_1"}';
        $header = $signer->signatureHeader($payload, 1812470400);

        self::assertSame(
            't=1812470400,v1=' . hash_hmac('sha256', '1812470400.' . $payload, 'whsec_test_secret'),
            $header,
        );
        self::assertTrue($signer->verify($payload, $header));
        self::assertFalse($signer->verify($payload, $header . 'bad'));
    }

    public function testDeliveryJobTransitionsQueuedToDeliveredWithEndpointSecretSignatureMetadata(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture(endpointSecret: 'whsec_endpoint_secret');
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.config.changed', ['version_id' => 'cfgv_1']);
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher);

        $processed = $job->deliver($delivery->delivery_id, static fn (): int => 200);

        self::assertSame('delivered', $processed->status);
        self::assertSame(1, $processed->retry_count);
        self::assertNull($processed->last_error);
        self::assertStringStartsWith('t=', (string) $processed->signature_header);
        self::assertTrue((new WebhookSigner('whsec_endpoint_secret'))->verify(
            $processed->payload_json,
            (string) $processed->signature_header,
        ));
        self::assertFalse((new WebhookSigner('whsec_wrong_secret'))->verify(
            $processed->payload_json,
            (string) $processed->signature_header,
        ));
        self::assertCount(1, $deliveries->attemptsForDelivery($delivery->delivery_id));
    }

    public function testDeliveryJobTransitionsQueuedToFailedAndRetainsLastErrorForRetry(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_1']);
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher);

        $failed = $job->deliver($delivery->delivery_id, static fn (): int => 503);
        $retry = $job->retry($delivery->delivery_id, static fn (): int => 200);

        self::assertSame('failed', $failed->status);
        self::assertSame(1, $failed->retry_count);
        self::assertSame('HTTP 503', $failed->last_error);
        self::assertNotNull($failed->last_attempt_at);
        self::assertSame(503, $failed->last_status_code);
        self::assertSame('delivered', $retry->status);
        self::assertSame(2, $retry->retry_count);
        self::assertNull($retry->last_error);
        self::assertCount(1, $deliveries->all());
        self::assertCount(2, $deliveries->attemptsForDelivery($delivery->delivery_id));
        self::assertSame($retry->delivery_id, $retry->toArray()['delivery_id']);
        self::assertSame('delivered', $retry->toArray()['status']);
    }

    public function testDeliveryJobSchedulesFailedRetriesWithExponentialBackoff(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_backoff']);
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher);

        $firstFailure = $job->deliver($delivery->delivery_id, static fn (): int => 503);
        $secondFailure = $job->retry($delivery->delivery_id, static fn (): int => 503);

        self::assertNotNull($firstFailure->last_attempt_at);
        self::assertSame(1, $firstFailure->retry_count);
        self::assertSame(
            300,
            $firstFailure->next_attempt_at->getTimestamp() - $firstFailure->last_attempt_at->getTimestamp(),
        );
        self::assertNotNull($secondFailure->last_attempt_at);
        self::assertSame(2, $secondFailure->retry_count);
        self::assertSame(
            600,
            $secondFailure->next_attempt_at->getTimestamp() - $secondFailure->last_attempt_at->getTimestamp(),
        );
    }

    public function testDeliveryJobMarksFinalFailureExhaustedAtRetryCap(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_exhausted']);
        $job = new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static fn (): int => 503,
            batchSize: 50,
            maxRetryCount: 2,
            baseBackoffSeconds: 60,
        );

        $firstFailure = $job->retry($delivery->delivery_id);
        $finalFailure = $job->retry($delivery->delivery_id);

        self::assertSame('failed', $firstFailure->status);
        self::assertSame(1, $firstFailure->retry_count);
        self::assertSame('exhausted', $finalFailure->status);
        self::assertSame(2, $finalFailure->retry_count);
        self::assertSame('HTTP 503', $finalFailure->last_error);
        self::assertNotNull($finalFailure->last_attempt_at);
        self::assertSame(
            $finalFailure->last_attempt_at->getTimestamp(),
            $finalFailure->next_attempt_at->getTimestamp(),
        );
        self::assertSame([], $deliveries->pendingRetry(10, maxRetryCount: 2));
        self::assertSame('exhausted', $finalFailure->toArray()['status']);
        self::assertCount(2, $deliveries->attemptsForDelivery($delivery->delivery_id));
    }

    public function testDeliveryJobMarksAlreadyCappedFailureExhaustedWithoutRedelivery(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $due = new DateTimeImmutable('2026-06-10T00:00:00+00:00');
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_pre_capped']);
        $deliveries->save($this->deliveryWithRetryState($delivery, retryCount: 3, nextAttemptAt: $due));
        $transportCalls = 0;
        $job = new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static function () use (&$transportCalls): int {
                ++$transportCalls;

                return 200;
            },
            batchSize: 50,
            maxRetryCount: 3,
            baseBackoffSeconds: 60,
        );

        $exhausted = $job->retry($delivery->delivery_id);

        self::assertSame(0, $transportCalls);
        self::assertSame('exhausted', $exhausted->status);
        self::assertSame(3, $exhausted->retry_count);
        self::assertSame(
            $due->modify('-5 minutes')->getTimestamp(),
            $exhausted->next_attempt_at->getTimestamp(),
        );
        self::assertSame([], $deliveries->attemptsForDelivery($delivery->delivery_id));
    }

    public function testDeliveryJobDoesNotRedeliverAlreadyDeliveredWebhook(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $delivery = $deliveries->queueForEndpoint($endpoint, 'campaign.status_changed', ['campaign_id' => 'cmp_1']);
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher);
        $delivered = $job->deliver($delivery->delivery_id, static fn (): int => 200);
        $transportCalls = 0;

        $again = $job->retry($delivery->delivery_id, static function () use (&$transportCalls): int {
            ++$transportCalls;

            return 500;
        });

        self::assertSame('delivered', $again->status);
        self::assertSame(1, $again->retry_count);
        self::assertSame($delivered->signature_header, $again->signature_header);
        self::assertSame(0, $transportCalls);
    }

    public function testDeliveryJobRejectsMissingDelivery(): void
    {
        [$deliveries, $endpoints, $cipher] = $this->deliveryFixture();
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery not found.');

        $job->deliver('missing', static fn (): int => 200);
    }

    public function testDeliveryJobRejectsDeliveryWhoseEndpointWasRemoved(): void
    {
        [$deliveries, , $cipher, $endpoint] = $this->deliveryFixture();
        $delivery = $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_1']);
        $job = new WebhookDeliveryJob($deliveries, new InMemoryWebhookEndpointRepository(), $cipher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook endpoint not found for delivery.');

        $job->deliver($delivery->delivery_id, static fn (): int => 200);
    }

    public function testWebhookRetryCronProcessesPendingDeliveriesAndLeavesDeliveredOnesAlone(): void
    {
        [$deliveries, $endpoints, $cipher, $queuedEndpoint] = $this->deliveryFixture(endpointUrl: 'https://example.test/queued');
        $failedEndpoint = $endpoints->store($this->endpointFixture(2, 'whe_failed', 'https://example.test/failed', $cipher));
        $deliveredEndpoint = $endpoints->store($this->endpointFixture(3, 'whe_delivered', 'https://example.test/delivered', $cipher));
        $queued = $deliveries->queueForEndpoint($queuedEndpoint, 'review.approved', ['asset_id' => 'ast_1']);
        $failed = $deliveries->queueForEndpoint($failedEndpoint, 'billing.points_changed', ['delta' => -120]);
        $delivered = $deliveries->queueForEndpoint($deliveredEndpoint, 'campaign.status_changed', ['status' => 'active']);
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher, static function (WebhookDelivery $delivery): int {
            return str_contains($delivery->endpoint_url, 'failed') ? 503 : 200;
        });
        $job->deliver($delivered->delivery_id, static fn (): int => 200);

        $result = $job->run();

        self::assertSame('webhook-retry', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(2, $result->metrics['processed'] ?? null);
        self::assertSame(1, $result->metrics['delivered'] ?? null);
        self::assertSame(1, $result->metrics['failed'] ?? null);
        self::assertSame(0, $result->metrics['exhausted'] ?? null);
        self::assertSame('delivered', $deliveries->find($queued->delivery_id)?->status);
        self::assertSame('failed', $deliveries->find($failed->delivery_id)?->status);
        self::assertSame('HTTP 503', $deliveries->find($failed->delivery_id)?->last_error);
        self::assertSame(1, $deliveries->find($queued->delivery_id)?->retry_count);
        self::assertSame(1, $deliveries->find($failed->delivery_id)?->retry_count);
        self::assertSame(1, $deliveries->find($delivered->delivery_id)?->retry_count);
    }

    public function testWebhookRetryCronReportsDeliveriesThatBecomeExhausted(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture(endpointUrl: 'https://example.test/exhausted');
        $due = new DateTimeImmutable('2026-06-10T00:00:00+00:00');
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_cron_exhausted']);
        $deliveries->save($this->deliveryWithRetryState($delivery, retryCount: 1, nextAttemptAt: $due));
        $job = new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static fn (): int => 503,
            batchSize: 50,
            maxRetryCount: 2,
            baseBackoffSeconds: 60,
        );

        $result = $job->run();

        self::assertSame('completed', $result->status);
        self::assertSame('Webhook retry batch completed.', $result->message);
        self::assertSame(1, $result->metrics['processed'] ?? null);
        self::assertSame(0, $result->metrics['delivered'] ?? null);
        self::assertSame(0, $result->metrics['failed'] ?? null);
        self::assertSame(1, $result->metrics['exhausted'] ?? null);
        self::assertSame('exhausted', $deliveries->find($delivery->delivery_id)?->status);
    }

    public function testWebhookRetryCronReportsPreviouslyFailedDeliveriesAlreadyAtRetryCap(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture(endpointUrl: 'https://example.test/already-capped');
        $due = new DateTimeImmutable('2026-06-10T00:00:00+00:00');
        $delivery = $deliveries->queueForEndpoint($endpoint, 'operations.error.created', ['error_id' => 'err_already_capped']);
        $deliveries->save($this->deliveryWithRetryState($delivery, retryCount: 2, nextAttemptAt: $due));
        $transportCalls = 0;
        $job = new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static function () use (&$transportCalls): int {
                ++$transportCalls;

                return 200;
            },
            batchSize: 50,
            maxRetryCount: 2,
            baseBackoffSeconds: 60,
        );

        $result = $job->run();

        self::assertSame(0, $transportCalls);
        self::assertSame(0, $result->metrics['processed'] ?? null);
        self::assertSame(0, $result->metrics['delivered'] ?? null);
        self::assertSame(0, $result->metrics['failed'] ?? null);
        self::assertSame(1, $result->metrics['exhausted'] ?? null);
        self::assertSame('Webhook retry batch completed.', $result->message);
        self::assertSame('exhausted', $deliveries->find($delivery->delivery_id)?->status);
    }

    public function testWebhookRetryCronReportsEmptyPendingQueue(): void
    {
        [$deliveries, $endpoints, $cipher] = $this->deliveryFixture();
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher);

        $result = $job->run();

        self::assertSame('webhook-retry', $result->jobName);
        self::assertSame(0, $result->metrics['processed'] ?? null);
        self::assertSame(0, $result->metrics['exhausted'] ?? null);
        self::assertSame('No pending webhook deliveries.', $result->message);
    }

    public function testWebhookRetryCronHonorsBatchSizeAndRejectsInvalidConfiguration(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $first = $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_1']);
        $second = $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_2']);
        $job = new WebhookDeliveryJob($deliveries, $endpoints, $cipher, static fn (): int => 200, 1);

        $result = $job->run();

        self::assertSame(1, $result->metrics['processed'] ?? null);
        self::assertSame('delivered', $deliveries->find($first->delivery_id)?->status);
        self::assertSame('queued', $deliveries->find($second->delivery_id)?->status);
        self::assertSame([], $deliveries->pendingRetry(0));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry batch size must be positive.');
        new WebhookDeliveryJob($deliveries, $endpoints, $cipher, static fn (): int => 200, 0);
    }

    public function testWebhookRetryCronRejectsInvalidRetryCapConfiguration(): void
    {
        [$deliveries, $endpoints, $cipher] = $this->deliveryFixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry cap must be positive.');

        new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static fn (): int => 200,
            batchSize: 50,
            maxRetryCount: 0,
        );
    }

    public function testWebhookRetryCronRejectsInvalidBackoffConfiguration(): void
    {
        [$deliveries, $endpoints, $cipher] = $this->deliveryFixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry backoff seconds must be positive.');

        new WebhookDeliveryJob(
            $deliveries,
            $endpoints,
            $cipher,
            static fn (): int => 200,
            batchSize: 50,
            maxRetryCount: 3,
            baseBackoffSeconds: 0,
        );
    }

    public function testInMemoryPendingRetryFiltersDeliveriesAtRetryCap(): void
    {
        [$deliveries, , , $endpoint] = $this->deliveryFixture();
        $due = new DateTimeImmutable('2026-06-10T00:00:00+00:00');
        $belowCap = $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_below_cap']);
        $atCap = $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_at_cap']);

        $deliveries->save($this->deliveryWithRetryState($belowCap, retryCount: 2, nextAttemptAt: $due));
        $deliveries->save($this->deliveryWithRetryState($atCap, retryCount: 3, nextAttemptAt: $due));
        $deliveries->save($this->deliveryWithRetryState(
            $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_exhausted']),
            retryCount: 2,
            nextAttemptAt: $due,
            status: 'exhausted',
        ));

        self::assertSame([$belowCap->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $deliveries->pendingRetry(10, $due),
        ));
    }

    public function testInMemoryPendingRetryRejectsInvalidRetryCap(): void
    {
        [$deliveries] = $this->deliveryFixture();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry cap must be positive.');

        $deliveries->pendingRetry(10, maxRetryCount: 0);
    }

    public function testInMemoryMarkDueRetriesExhaustedHandlesInvalidLimits(): void
    {
        [$deliveries] = $this->deliveryFixture();

        self::assertSame([], $deliveries->markDueRetriesExhausted(0));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook retry cap must be positive.');

        $deliveries->markDueRetriesExhausted(10, maxRetryCount: 0);
    }

    public function testInMemoryDeliveryRepositoryFiltersOrganizationDeliveriesAndRejectsMissingEndpointInternalId(): void
    {
        [$deliveries, $endpoints, $cipher, $endpoint] = $this->deliveryFixture();
        $otherEndpoint = $endpoints->store($this->endpointFixture(2, 'whe_other', 'https://example.test/other', $cipher));
        $first = $deliveries->queueForEndpoint($endpoint, 'review.approved', ['asset_id' => 'ast_1']);
        $second = $deliveries->queueForEndpoint($otherEndpoint, 'billing.points_changed', ['ledger_id' => 'led_1']);

        self::assertSame([], $deliveries->listForOrganization(0));
        self::assertSame([$first->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $deliveries->listForOrganization(99, endpointId: 'whe_test', status: 'queued', limit: 10),
        ));
        self::assertSame([$first->delivery_id, $second->delivery_id], array_map(
            static fn (WebhookDelivery $delivery): string => $delivery->delivery_id,
            $deliveries->listForOrganization(99, endpointId: ' ', status: '', limit: 10),
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook endpoint internal ID is required to queue a delivery.');

        $deliveries->queueForEndpoint(new WebhookEndpoint(
            id: null,
            endpointId: 'whe_missing_id',
            organizationId: 99,
            createdByUserId: 7,
            name: 'Missing internal ID',
            endpointUrl: 'https://example.test/missing-id',
            status: 'active',
            events: ['review.approved'],
            encryptedSigningSecret: $cipher->encrypt('whsec_missing_id'),
            secretPreview: $cipher->preview('whsec_missing_id'),
            secretRotatedAt: new DateTimeImmutable('2026-06-10T00:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-06-10T00:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-06-10T00:00:00+00:00'),
        ), 'review.approved', ['asset_id' => 'ast_missing']);
    }

    public function testWebhookHttpTransportRejectsInvalidTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Webhook HTTP timeout seconds must be positive.');

        WebhookDeliveryJob::httpTransport(0);
    }

    public function testWebhookHttpTransportMapsStreamResultsToStatusCodes(): void
    {
        $transport = WebhookDeliveryJob::httpTransport(1);
        $now = new DateTimeImmutable('2026-06-10T00:00:00+00:00');

        $ok = new WebhookDelivery(
            delivery_id: 'whd_ok',
            organization_id: 99,
            webhook_endpoint_id: 1,
            endpoint_id: 'whe_ok',
            endpoint_url: 'data://text/plain,ok',
            event_type: 'review.approved',
            payload_json: '{}',
            status: 'queued',
            retry_count: 0,
            next_attempt_at: $now,
            last_attempt_at: null,
            last_status_code: null,
            last_error: null,
            signature_header: null,
            created_at: $now,
            delivered_at: null,
        );
        $failed = new WebhookDelivery(
            delivery_id: 'whd_failed',
            organization_id: 99,
            webhook_endpoint_id: 1,
            endpoint_id: 'whe_failed',
            endpoint_url: 'file://',
            event_type: 'review.approved',
            payload_json: '{}',
            status: 'queued',
            retry_count: 0,
            next_attempt_at: $now,
            last_attempt_at: null,
            last_status_code: null,
            last_error: null,
            signature_header: null,
            created_at: $now,
            delivered_at: null,
        );

        self::assertSame(200, $transport($ok, 't=1,v1=test'));
        self::assertSame(0, $transport($failed, 't=1,v1=test'));
        self::assertSame(202, WebhookDeliveryJob::statusCodeFromTransportResult('accepted', ['HTTP/1.1 202 Accepted']));
    }

    /**
     * @return array{0:InMemoryWebhookDeliveryRepository, 1:InMemoryWebhookEndpointRepository, 2:WebhookEndpointSecretCipherInterface, 3:WebhookEndpoint}
     */
    private function deliveryFixture(
        string $endpointSecret = 'whsec_test_secret',
        string $endpointUrl = 'https://example.test/webhooks',
    ): array {
        $deliveries = new InMemoryWebhookDeliveryRepository();
        $endpoints = new InMemoryWebhookEndpointRepository();
        $cipher = $this->cipher();
        $endpoint = $endpoints->store($this->endpointFixture(1, 'whe_test', $endpointUrl, $cipher, $endpointSecret));

        return [$deliveries, $endpoints, $cipher, $endpoint];
    }

    private function endpointFixture(
        int $id,
        string $endpointId,
        string $endpointUrl,
        WebhookEndpointSecretCipherInterface $cipher,
        string $secret = 'whsec_test_secret',
    ): WebhookEndpoint {
        $now = new DateTimeImmutable('2026-06-10T00:00:00+00:00', new DateTimeZone('UTC'));

        return new WebhookEndpoint(
            id: $id,
            endpointId: $endpointId,
            organizationId: 99,
            createdByUserId: 7,
            name: 'Test endpoint',
            endpointUrl: $endpointUrl,
            status: 'active',
            events: ['review.approved', 'billing.points_changed', 'campaign.status_changed'],
            encryptedSigningSecret: $cipher->encrypt($secret),
            secretPreview: $cipher->preview($secret),
            secretRotatedAt: $now,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function deliveryWithRetryState(
        WebhookDelivery $delivery,
        int $retryCount,
        DateTimeImmutable $nextAttemptAt,
        string $status = 'failed',
    ): WebhookDelivery {
        return new WebhookDelivery(
            delivery_id: $delivery->delivery_id,
            organization_id: $delivery->organization_id,
            webhook_endpoint_id: $delivery->webhook_endpoint_id,
            endpoint_id: $delivery->endpoint_id,
            endpoint_url: $delivery->endpoint_url,
            event_type: $delivery->event_type,
            payload_json: $delivery->payload_json,
            status: $status,
            retry_count: $retryCount,
            next_attempt_at: $nextAttemptAt,
            last_attempt_at: $nextAttemptAt->modify('-5 minutes'),
            last_status_code: 503,
            last_error: 'HTTP 503',
            signature_header: $delivery->signature_header,
            created_at: $delivery->created_at,
            delivered_at: null,
        );
    }

    private function cipher(): WebhookEndpointSecretCipherInterface
    {
        return new class implements WebhookEndpointSecretCipherInterface {
            public function generateSigningSecret(): string
            {
                return 'whsec_test_secret';
            }

            public function generateSecret(): string
            {
                return $this->generateSigningSecret();
            }

            public function encrypt(string $plaintext): string
            {
                return 'test:v1:' . $plaintext;
            }

            public function decrypt(string $ciphertext): string
            {
                return str_starts_with($ciphertext, 'test:v1:') ? substr($ciphertext, 8) : $ciphertext;
            }

            public function preview(string $plaintext): string
            {
                return 'whsec_...' . substr($plaintext, -6);
            }
        };
    }
}
