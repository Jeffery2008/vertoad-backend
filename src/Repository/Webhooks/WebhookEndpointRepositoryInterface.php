<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use VertoAD\Domain\Webhooks\WebhookEndpoint;

interface WebhookEndpointRepositoryInterface
{
    public function store(WebhookEndpoint $endpoint): WebhookEndpoint;

    public function update(WebhookEndpoint $endpoint): WebhookEndpoint;

    public function findById(int $id): ?WebhookEndpoint;

    public function findForOrganization(string $endpointId, int $organizationId): ?WebhookEndpoint;

    /**
     * @return list<WebhookEndpoint>
     */
    public function listForOrganization(int $organizationId): array;

    public function rotateSecret(
        string $endpointId,
        int $organizationId,
        string $encryptedSigningSecret,
        string $secretPreview,
        DateTimeImmutable $secretRotatedAt,
    ): ?WebhookEndpoint;
}
