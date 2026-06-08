<?php

declare(strict_types=1);

namespace VertoAD\Service\Webhooks;

use DateTimeImmutable;
use RuntimeException;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;

final readonly class WebhookDeliveryJob
{
    public function __construct(
        private WebhookDeliveryRepositoryInterface $deliveries,
        private WebhookSigner $signer,
    ) {
    }

    /**
     * @param callable(WebhookDelivery, string):int $transport
     */
    public function deliver(string $deliveryId, callable $transport): WebhookDelivery
    {
        $delivery = $this->requiredDelivery($deliveryId);
        $signature = $this->signer->signatureHeader($delivery->payload_json);
        $statusCode = $transport($delivery, $signature);
        $delivered = $statusCode >= 200 && $statusCode < 300;

        return $this->deliveries->save(new WebhookDelivery(
            delivery_id: $delivery->delivery_id,
            endpoint_url: $delivery->endpoint_url,
            event_type: $delivery->event_type,
            payload_json: $delivery->payload_json,
            status: $delivered ? 'delivered' : 'failed',
            retry_count: $delivery->retry_count + 1,
            last_error: $delivered ? null : 'HTTP ' . $statusCode,
            signature_header: $signature,
            created_at: $delivery->created_at,
            delivered_at: $delivered ? new DateTimeImmutable() : null,
        ));
    }

    /**
     * @param callable(WebhookDelivery, string):int $transport
     */
    public function retry(string $deliveryId, callable $transport): WebhookDelivery
    {
        return $this->deliver($deliveryId, $transport);
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
