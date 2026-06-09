<?php

declare(strict_types=1);

namespace VertoAD\Service\Webhooks;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Service\Cron\CronJobInterface;

final readonly class WebhookDeliveryJob implements CronJobInterface
{
    private Closure $transport;

    /**
     * @param null|callable(WebhookDelivery, string):int $transport
     */
    public function __construct(
        private WebhookDeliveryRepositoryInterface $deliveries,
        private WebhookEndpointRepositoryInterface $endpoints,
        private WebhookEndpointSecretCipherInterface $secrets,
        ?callable $transport = null,
        private int $batchSize = 50,
    ) {
        if ($batchSize <= 0) {
            throw new \InvalidArgumentException('Webhook retry batch size must be positive.');
        }

        $this->transport = Closure::fromCallable($transport ?? self::httpTransport(5));
    }

    /**
     * @return callable(WebhookDelivery, string):int
     */
    public static function httpTransport(int $timeoutSeconds): callable
    {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Webhook HTTP timeout seconds must be positive.');
        }

        return static function (WebhookDelivery $delivery, string $signature) use ($timeoutSeconds): int {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        'Content-Type: application/json',
                        'User-Agent: VertoAD-Webhook-Retry/1.0',
                        'X-VertoAD-Signature: ' . $signature,
                    ]),
                    'content' => $delivery->payload_json,
                    'ignore_errors' => true,
                    'timeout' => $timeoutSeconds,
                ],
            ]);
            $response = @file_get_contents($delivery->endpoint_url, false, $context);
            return self::statusCodeFromTransportResult($response, $http_response_header ?? []);
        };
    }

    /**
     * @param list<string> $headers
     */
    public static function statusCodeFromTransportResult(string|false $response, array $headers): int
    {
        if (isset($headers[0]) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', (string) $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return $response === false ? 0 : 200;
    }

    public function name(): string
    {
        return 'webhook-retry';
    }

    public function run(): CronJobResult
    {
        $processed = 0;
        $delivered = 0;
        $failed = 0;

        foreach ($this->deliveries->pendingRetry($this->batchSize) as $delivery) {
            ++$processed;
            $result = $this->retry($delivery->delivery_id, $this->transport);
            if ($result->status === 'delivered') {
                ++$delivered;
            } else {
                ++$failed;
            }
        }

        return CronJobResult::completed($this->name(), [
            'processed' => $processed,
            'delivered' => $delivered,
            'failed' => $failed,
        ], $processed === 0 ? 'No pending webhook deliveries.' : 'Webhook retry batch completed.');
    }

    /**
     * @param callable(WebhookDelivery, string):int $transport
     */
    public function deliver(string $deliveryId, callable $transport): WebhookDelivery
    {
        $delivery = $this->requiredDelivery($deliveryId);
        if ($delivery->status === 'delivered') {
            return $delivery;
        }

        $endpoint = $this->endpoints->findById($delivery->webhook_endpoint_id);
        if ($endpoint === null) {
            throw new RuntimeException('Webhook endpoint not found for delivery.');
        }

        $signature = (new WebhookSigner($this->secrets->decrypt($endpoint->encryptedSigningSecret)))
            ->signatureHeader($delivery->payload_json);
        $attemptedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $started = microtime(true);
        $statusCode = $transport($delivery, $signature);
        $durationMs = max(0, (int) round((microtime(true) - $started) * 1000));
        $delivered = $statusCode >= 200 && $statusCode < 300;
        $retryCount = $delivery->retry_count + 1;

        $updated = $this->deliveries->save(new WebhookDelivery(
            delivery_id: $delivery->delivery_id,
            organization_id: $delivery->organization_id,
            webhook_endpoint_id: $delivery->webhook_endpoint_id,
            endpoint_id: $delivery->endpoint_id,
            endpoint_url: $delivery->endpoint_url,
            event_type: $delivery->event_type,
            payload_json: $delivery->payload_json,
            status: $delivered ? 'delivered' : 'failed',
            retry_count: $retryCount,
            next_attempt_at: $delivered ? $attemptedAt : $attemptedAt->modify('+5 minutes'),
            last_attempt_at: $attemptedAt,
            last_status_code: $statusCode > 0 ? $statusCode : null,
            last_error: $delivered ? null : 'HTTP ' . $statusCode,
            signature_header: $signature,
            created_at: $delivery->created_at,
            delivered_at: $delivered ? $attemptedAt : null,
        ));

        $this->deliveries->recordAttempt(
            deliveryId: $delivery->delivery_id,
            attemptNumber: $retryCount,
            statusCode: $statusCode > 0 ? $statusCode : null,
            error: $delivered ? null : 'HTTP ' . $statusCode,
            signatureHeader: $signature,
            attemptedAt: $attemptedAt,
            durationMs: $durationMs,
        );

        return $updated;
    }

    /**
     * @param callable(WebhookDelivery, string):int|null $transport
     */
    public function retry(string $deliveryId, ?callable $transport = null): WebhookDelivery
    {
        return $this->deliver($deliveryId, $transport ?? $this->transport);
    }

    private function requiredDelivery(string $deliveryId): WebhookDelivery
    {
        $delivery = $this->deliveries->find($deliveryId);
        if ($delivery === null) {
            throw new RuntimeException('Webhook delivery not found.');
        }

        return $delivery;
    }
}
