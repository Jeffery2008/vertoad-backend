<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use DateTimeImmutable;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;

final class InMemoryWebhookEndpointRepository implements WebhookEndpointRepositoryInterface
{
    /** @var array<int, WebhookEndpoint> */
    private array $byId = [];

    /** @var array<string, WebhookEndpoint> */
    private array $byEndpointId = [];

    public function store(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        $stored = $endpoint->id === null ? $endpoint->withId(count($this->byId) + 1) : $endpoint;
        $this->byId[(int) $stored->id] = $stored;
        $this->byEndpointId[$this->key($stored->endpointId, $stored->organizationId)] = $stored;

        return $stored;
    }

    public function update(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        if ($endpoint->id === null) {
            throw new \InvalidArgumentException('Webhook endpoint internal ID is required for update.');
        }

        $this->byId[$endpoint->id] = $endpoint;
        $this->byEndpointId[$this->key($endpoint->endpointId, $endpoint->organizationId)] = $endpoint;

        return $endpoint;
    }

    public function findById(int $id): ?WebhookEndpoint
    {
        return $this->byId[$id] ?? null;
    }

    public function findForOrganization(string $endpointId, int $organizationId): ?WebhookEndpoint
    {
        return $this->byEndpointId[$this->key($endpointId, $organizationId)] ?? null;
    }

    public function listForOrganization(int $organizationId): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (WebhookEndpoint $endpoint): bool => $endpoint->organizationId === $organizationId,
        ));
    }

    public function listActiveForEvent(int $organizationId, string $eventType): array
    {
        $eventType = trim($eventType);

        return array_values(array_filter(
            $this->byId,
            static fn (WebhookEndpoint $endpoint): bool =>
                $endpoint->organizationId === $organizationId
                && $endpoint->enabled()
                && in_array($eventType, $endpoint->events, true),
        ));
    }

    public function rotateSecret(
        string $endpointId,
        int $organizationId,
        string $encryptedSigningSecret,
        string $secretPreview,
        DateTimeImmutable $secretRotatedAt,
    ): ?WebhookEndpoint {
        $endpoint = $this->findForOrganization($endpointId, $organizationId);
        if ($endpoint === null) {
            return null;
        }

        return $this->update(new WebhookEndpoint(
            id: $endpoint->id,
            endpointId: $endpoint->endpointId,
            organizationId: $endpoint->organizationId,
            createdByUserId: $endpoint->createdByUserId,
            name: $endpoint->name,
            endpointUrl: $endpoint->endpointUrl,
            status: $endpoint->status,
            events: $endpoint->events,
            encryptedSigningSecret: $encryptedSigningSecret,
            secretPreview: $secretPreview,
            secretRotatedAt: $secretRotatedAt,
            createdAt: $endpoint->createdAt,
            updatedAt: $secretRotatedAt,
        ));
    }

    private function key(string $endpointId, int $organizationId): string
    {
        return $organizationId . ':' . trim($endpointId);
    }
}
