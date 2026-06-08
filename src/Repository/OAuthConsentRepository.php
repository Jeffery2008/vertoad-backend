<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Auth\OAuthClient;

final readonly class OAuthConsentRepository implements OAuthConsentRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function grantConsent(OAuthClient $client, int $userId, ?int $organizationId, array $scopes, DateTimeImmutable $now): void
    {
        $existingId = $this->findActiveConsentId($client, $userId, $organizationId);
        $payload = $this->encodeList($scopes);
        $grantedAt = $this->format($now);

        if ($existingId === null) {
            $this->connection->insert('oauth_user_consents', [
                'client_id' => $client->id,
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'scopes_json' => $payload,
                'granted_at' => $grantedAt,
                'revoked_at' => null,
            ]);

            return;
        }

        $this->connection->executeStatement(
            'UPDATE oauth_user_consents SET scopes_json = ?, granted_at = ?, revoked_at = NULL WHERE id = ?',
            [$payload, $grantedAt, $existingId],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    public function hasConsentFor(OAuthClient $client, int $userId, ?int $organizationId, array $scopes): bool
    {
        $row = $this->connection->createQueryBuilder()
            ->select('scopes_json')
            ->from('oauth_user_consents')
            ->where('client_id = :client_id')
            ->andWhere('user_id = :user_id')
            ->andWhere($organizationId === null ? 'organization_id IS NULL' : 'organization_id = :organization_id')
            ->andWhere('revoked_at IS NULL')
            ->setParameter('client_id', $client->id)
            ->setParameter('user_id', $userId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAssociative();

        if ($row === false) {
            return false;
        }

        $granted = $this->decodeList((string) $row['scopes_json']);
        foreach ($scopes as $scope) {
            if (!in_array($scope, $granted, true)) {
                return false;
            }
        }

        return true;
    }

    private function findActiveConsentId(OAuthClient $client, int $userId, ?int $organizationId): ?int
    {
        $builder = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('oauth_user_consents')
            ->where('client_id = :client_id')
            ->andWhere('user_id = :user_id')
            ->andWhere($organizationId === null ? 'organization_id IS NULL' : 'organization_id = :organization_id')
            ->andWhere('revoked_at IS NULL')
            ->setParameter('client_id', $client->id)
            ->setParameter('user_id', $userId);

        if ($organizationId !== null) {
            $builder->setParameter('organization_id', $organizationId);
        }

        $id = $builder->fetchOne();

        return $id === false ? null : (int) $id;
    }

    /** @param list<string> $values */
    private function encodeList(array $values): string
    {
        return json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
