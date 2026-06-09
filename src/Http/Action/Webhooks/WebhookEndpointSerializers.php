<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use Psr\Http\Message\ResponseInterface;
use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;

final class WebhookEndpointSerializers
{
    /** @return array<string, mixed> */
    public static function endpoint(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'endpoint_id' => $endpoint->endpointId,
            'organization_id' => $endpoint->organizationId,
            'created_by_user_id' => $endpoint->createdByUserId,
            'name' => $endpoint->name,
            'endpoint_url' => $endpoint->endpointUrl,
            'events' => $endpoint->events,
            'status' => $endpoint->status,
            'enabled' => $endpoint->enabled(),
            'secret_preview' => $endpoint->secretPreview,
            'secret_rotated_at' => $endpoint->secretRotatedAt->format(DATE_ATOM),
            'created_at' => $endpoint->createdAt->format(DATE_ATOM),
            'updated_at' => $endpoint->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<WebhookEndpoint> $endpoints
     * @return list<array<string, mixed>>
     */
    public static function endpoints(array $endpoints): array
    {
        return array_map(static fn (WebhookEndpoint $endpoint): array => self::endpoint($endpoint), $endpoints);
    }

    /** @return array<string, mixed> */
    public static function delivery(WebhookDelivery $delivery): array
    {
        return $delivery->toArray();
    }

    /**
     * @param list<WebhookDelivery> $deliveries
     * @return list<array<string, mixed>>
     */
    public static function deliveries(array $deliveries): array
    {
        return array_map(static fn (WebhookDelivery $delivery): array => self::delivery($delivery), $deliveries);
    }

    /** @param array<string, mixed> $payload */
    public static function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }

    public static function error(ResponseInterface $response, int $statusCode, string $code, string $message): ResponseInterface
    {
        return self::json($response, ['code' => $code, 'message' => $message], $statusCode);
    }
}
