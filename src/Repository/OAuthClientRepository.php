<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Auth\OAuthClient;

final class OAuthClientRepository implements OAuthClientRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }

    public function store(OAuthClient $client): OAuthClient
    {
        $client = $client->normalized();
        $this->connection->insert(
            'oauth_clients',
            [
                'organization_id' => $client->organizationId,
                'owner_user_id' => $client->ownerUserId,
                'client_identifier' => $client->clientIdentifier,
                'name' => $client->name,
                'secret_hash' => $client->secretHash,
                'redirect_uris_json' => json_encode($client->redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'grant_types_json' => json_encode($client->grantTypes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'scopes_json' => json_encode($client->scopes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'is_confidential' => $client->isConfidential ? 1 : 0,
                'revoked_at' => $this->formatDateTime($client->revokedAt),
            ],
            [
                'organization_id' => $client->organizationId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'owner_user_id' => $client->ownerUserId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'client_identifier' => ParameterType::STRING,
                'name' => ParameterType::STRING,
                'secret_hash' => $client->secretHash === null ? ParameterType::NULL : ParameterType::STRING,
                'redirect_uris_json' => ParameterType::STRING,
                'grant_types_json' => ParameterType::STRING,
                'scopes_json' => ParameterType::STRING,
                'is_confidential' => ParameterType::INTEGER,
                'revoked_at' => $client->revokedAt === null ? ParameterType::NULL : ParameterType::STRING,
            ],
        );

        $id = (int) $this->connection->lastInsertId();

        return $id > 0 ? $client->withId($id) : $client;
    }

    public function findActiveByIdentifier(string $clientIdentifier): ?OAuthClient
    {
        $clientIdentifier = trim($clientIdentifier);
        if ($clientIdentifier === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('oauth_clients')
            ->where('client_identifier = :client_identifier')
            ->andWhere('revoked_at IS NULL')
            ->setParameter('client_identifier', $clientIdentifier)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function listActiveForOrganization(int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('oauth_clients')
            ->where('organization_id = :organization_id')
            ->andWhere('revoked_at IS NULL')
            ->orderBy('id', 'ASC')
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        return array_map(fn (array $row): OAuthClient => $this->hydrate($row), $rows);
    }

    public function rotateSecret(string $clientIdentifier, string $secretHash): bool
    {
        $clientIdentifier = trim($clientIdentifier);
        $secretHash = trim($secretHash);
        if ($clientIdentifier === '' || $secretHash === '') {
            return false;
        }

        $affected = $this->connection->executeStatement(
            'UPDATE oauth_clients SET secret_hash = ? WHERE client_identifier = ? AND is_confidential = 1 AND revoked_at IS NULL',
            [$secretHash, $clientIdentifier],
            [ParameterType::STRING, ParameterType::STRING],
        );

        return $affected === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OAuthClient
    {
        return (new OAuthClient(
            id: (int) $row['id'],
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            ownerUserId: $row['owner_user_id'] === null ? null : (int) $row['owner_user_id'],
            clientIdentifier: (string) $row['client_identifier'],
            name: (string) $row['name'],
            secretHash: $row['secret_hash'] === null ? null : (string) $row['secret_hash'],
            redirectUris: $this->decodeStringList((string) $row['redirect_uris_json']),
            grantTypes: $this->decodeStringList((string) $row['grant_types_json']),
            scopes: $row['scopes_json'] === null ? [] : $this->decodeStringList((string) $row['scopes_json']),
            isConfidential: (bool) $row['is_confidential'],
            revokedAt: $this->parseNullableDateTime($row['revoked_at'] === null ? null : (string) $row['revoked_at']),
        ))->normalized();
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $value): bool => is_string($value)));
    }

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    private function parseNullableDateTime(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
