<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use PHPUnit\Framework\TestCase;

final class WebhookDeliveryServiceTest extends TestCase
{
    public function testWebhookSignerUsesHmacSha256AndProducesVerifiableHeader(): void
    {
        $signerClass = 'VertoAD\\Service\\Webhooks\\WebhookSigner';
        self::assertTrue(class_exists($signerClass), $signerClass . ' must exist.');

        $signer = new $signerClass('whsec_test_secret');
        $payload = '{"event":"operations.error.created","id":"evt_1"}';
        $header = $signer->signatureHeader($payload, 1812470400);

        self::assertSame(
            't=1812470400,v1=' . hash_hmac('sha256', '1812470400.' . $payload, 'whsec_test_secret'),
            $header,
        );
        self::assertTrue($signer->verify($payload, $header));
        self::assertFalse($signer->verify($payload, $header . 'bad'));
    }

    public function testDeliveryJobTransitionsQueuedToDeliveredWithSignatureMetadata(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Webhooks\\InMemoryWebhookDeliveryRepository';
        $jobClass = 'VertoAD\\Service\\Webhooks\\WebhookDeliveryJob';
        $signerClass = 'VertoAD\\Service\\Webhooks\\WebhookSigner';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($jobClass), $jobClass . ' must exist.');
        self::assertTrue(class_exists($signerClass), $signerClass . ' must exist.');

        $repository = new $repositoryClass();
        $delivery = $repository->queue(
            endpointUrl: 'https://example.test/webhooks',
            eventType: 'operations.config.changed',
            payload: ['version_id' => 'cfgv_1'],
        );
        $job = new $jobClass($repository, new $signerClass('whsec_test_secret'));

        $processed = $job->deliver((string) $this->value($delivery, 'delivery_id'), static fn (): int => 200);

        self::assertSame('delivered', $this->value($processed, 'status'));
        self::assertSame(1, $this->value($processed, 'retry_count'));
        self::assertNull($this->value($processed, 'last_error'));
        self::assertStringStartsWith('t=', (string) $this->value($processed, 'signature_header'));
        self::assertTrue((new $signerClass('whsec_test_secret'))->verify(
            (string) $this->value($processed, 'payload_json'),
            (string) $this->value($processed, 'signature_header'),
        ));
    }

    public function testDeliveryJobTransitionsQueuedToFailedAndRetainsLastErrorForRetry(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Webhooks\\InMemoryWebhookDeliveryRepository';
        $jobClass = 'VertoAD\\Service\\Webhooks\\WebhookDeliveryJob';
        $signerClass = 'VertoAD\\Service\\Webhooks\\WebhookSigner';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($jobClass), $jobClass . ' must exist.');
        self::assertTrue(class_exists($signerClass), $signerClass . ' must exist.');

        $repository = new $repositoryClass();
        $delivery = $repository->queue(
            endpointUrl: 'https://example.test/webhooks',
            eventType: 'operations.error.created',
            payload: ['error_id' => 'err_1'],
        );
        $job = new $jobClass($repository, new $signerClass('whsec_test_secret'));

        $failed = $job->deliver((string) $this->value($delivery, 'delivery_id'), static fn (): int => 503);
        $retry = $job->retry((string) $this->value($delivery, 'delivery_id'), static fn (): int => 200);

        self::assertSame('failed', $this->value($failed, 'status'));
        self::assertSame(1, $this->value($failed, 'retry_count'));
        self::assertSame('HTTP 503', $this->value($failed, 'last_error'));
        self::assertSame('delivered', $this->value($retry, 'status'));
        self::assertSame(2, $this->value($retry, 'retry_count'));
        self::assertNull($this->value($retry, 'last_error'));
        self::assertCount(1, $repository->all());
        self::assertSame($retry->delivery_id, $retry->toArray()['delivery_id']);
        self::assertSame('delivered', $retry->toArray()['status']);
    }

    public function testDeliveryJobRejectsMissingDelivery(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Webhooks\\InMemoryWebhookDeliveryRepository';
        $jobClass = 'VertoAD\\Service\\Webhooks\\WebhookDeliveryJob';
        $signerClass = 'VertoAD\\Service\\Webhooks\\WebhookSigner';
        $job = new $jobClass(new $repositoryClass(), new $signerClass('whsec_test_secret'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Webhook delivery not found.');

        $job->deliver('missing', static fn (): int => 200);
    }

    private function value(mixed $record, string $key): mixed
    {
        if (is_array($record)) {
            return $record[$key] ?? null;
        }

        if (is_object($record)) {
            return $record->{$key} ?? null;
        }

        return null;
    }
}
