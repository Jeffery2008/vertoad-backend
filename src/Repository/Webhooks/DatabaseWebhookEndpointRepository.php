<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Webhooks\WebhookEndpoint;

final readonly class DatabaseWebhookEndpointRepository implements WebhookEndpointRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function store(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        return $this->connection->transactional(function () use ($endpoint): WebhookEndpoint {
            $this->connection->insert('webhook_endpoints', $this->rowFromEndpoint($endpoint), $this->rowTypes($endpoint));
            $id = (int) $this->connection->lastInsertId();
            $stored = $endpoint->withId($id);
            $this->replaceEvents($id, $stored->events);

            return $stored;
        });
    }

    public function update(WebhookEndpoint $endpoint): WebhookEndpoint
    {
        if ($endpoint->id === null) {
            throw new \InvalidArgumentException('Webhook endpoint internal ID is required for update.');
        }

        return $this->connection->transactional(function () use ($endpoint): WebhookEndpoint {
            $this->connection->update(
                'webhook_endpoints',
                $this->rowFromEndpoint($endpoint),
                ['id' => $endpoint->id],
            );
            $this->replaceEvents($endpoint->id, $endpoint->events);

            return $endpoint;
        });
    }

    public function findById(int $id): ?WebhookEndpoint
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('webhook_endpoints')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findForOrganization(string $endpointId, int $organizationId): ?WebhookEndpoint
    {
        $endpointId = trim($endpointId);
        if ($endpointId === '' || $organizationId <= 0) {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('webhook_endpoints')
            ->where('endpoint_id = :endpoint_id')
            ->andWhere('organization_id = :organization_id')
            ->setParameter('endpoint_id', $endpointId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function listForOrganization(int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('webhook_endpoints')
            ->where('organization_id = :organization_id')
            ->orderBy('id', 'ASC')
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        return array_map(fn (array $row): WebhookEndpoint => $this->hydrate($row), $rows);
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

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->connection->update(
            'webhook_endpoints',
            [
                'encrypted_signing_secret' => trim($encryptedSigningSecret),
                'secret_preview' => trim($secretPreview),
                'secret_rotated_at' => $this->formatDate($secretRotatedAt),
                'updated_at' => $this->formatDate($now),
            ],
            ['id' => $endpoint->id],
        );

        return $this->findById((int) $endpoint->id);
    }

    /**
     * @param list<string> $events
     */
    private function replaceEvents(int $endpointId, array $events): void
    {
        $this->connection->delete('webhook_endpoint_events', ['webhook_endpoint_id' => $endpointId]);
        foreach ($events as $event) {
            $this->connection->insert('webhook_endpoint_events', [
                'webhook_endpoint_id' => $endpointId,
                'event_type' => $event,
            ]);
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function rowFromEndpoint(WebhookEndpoint $endpoint): array
    {
        return [
            'endpoint_id' => $endpoint->endpointId,
            'organization_id' => $endpoint->organizationId,
            'created_by_user_id' => $endpoint->createdByUserId,
            'name' => $endpoint->name,
            'endpoint_url' => $endpoint->endpointUrl,
            'status' => $endpoint->status,
            'encrypted_signing_secret' => $endpoint->encryptedSigningSecret,
            'secret_preview' => $endpoint->secretPreview,
            'secret_rotated_at' => $this->formatDate($endpoint->secretRotatedAt),
            'created_at' => $this->formatDate($endpoint->createdAt),
            'updated_at' => $this->formatDate($endpoint->updatedAt),
        ];
    }

    /**
     * @return array<string, ParameterType>
     */
    private function rowTypes(WebhookEndpoint $endpoint): array
    {
        return [
            'organization_id' => ParameterType::INTEGER,
            'created_by_user_id' => ParameterType::INTEGER,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WebhookEndpoint
    {
        $id = (int) $row['id'];

        return new WebhookEndpoint(
            id: $id,
            endpointId: (string) $row['endpoint_id'],
            organizationId: (int) $row['organization_id'],
            createdByUserId: (int) $row['created_by_user_id'],
            name: (string) $row['name'],
            endpointUrl: (string) $row['endpoint_url'],
            status: (string) $row['status'],
            events: $this->eventsForEndpoint($id),
            encryptedSigningSecret: (string) $row['encrypted_signing_secret'],
            secretPreview: (string) $row['secret_preview'],
            secretRotatedAt: $this->parseDate((string) $row['secret_rotated_at']),
            createdAt: $this->parseDate((string) $row['created_at']),
            updatedAt: $this->parseDate((string) $row['updated_at']),
        );
    }

    /**
     * @return list<string>
     */
    private function eventsForEndpoint(int $endpointId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['event_type'],
            $this->connection->createQueryBuilder()
                ->select('event_type')
                ->from('webhook_endpoint_events')
                ->where('webhook_endpoint_id = :endpoint_id')
                ->orderBy('event_type', 'ASC')
                ->setParameter('endpoint_id', $endpointId)
                ->fetchAllAssociative(),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
