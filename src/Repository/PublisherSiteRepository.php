<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteStatus;

final class PublisherSiteRepository implements PublisherSiteRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(int $organizationId, string $name, string $domain, string $verificationToken): PublisherSite
    {
        $this->connection->insert('sites', [
            'organization_id' => $organizationId,
            'name' => $name,
            'domain' => $domain,
            'status' => PublisherSiteStatus::Pending->value,
            'verification_token' => $verificationToken,
            'verified_at' => null,
        ]);

        return new PublisherSite(
            id: (int) $this->connection->lastInsertId(),
            organizationId: $organizationId,
            domain: $domain,
            status: PublisherSiteStatus::Pending,
            verificationToken: $verificationToken,
            verifiedAt: null,
            name: $name,
        );
    }

    /**
     * @return list<PublisherSite>
     */
    public function listForOrganization(int $organizationId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'organization_id', 'name', 'domain', 'status', 'verification_token', 'verified_at')
            ->from('sites')
            ->where('organization_id = :organization_id')
            ->orderBy('id', 'ASC')
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        return array_map(fn (array $row): PublisherSite => $this->mapRow($row), $rows);
    }

    public function findById(int $id): ?PublisherSite
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'organization_id', 'name', 'domain', 'status', 'verification_token', 'verified_at')
            ->from('sites')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row === false ? null : $this->mapRow($row);
    }

    public function markVerified(PublisherSite $site, DateTimeImmutable $verifiedAt): PublisherSite
    {
        $this->connection->update('sites', [
            'status' => PublisherSiteStatus::Verified->value,
            'verified_at' => $verifiedAt->format('Y-m-d H:i:s'),
        ], ['id' => $site->id]);

        return $site->withVerification(PublisherSiteStatus::Verified, $verifiedAt);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): PublisherSite
    {
        return new PublisherSite(
            id: (int) $row['id'],
            organizationId: (int) $row['organization_id'],
            domain: (string) $row['domain'],
            status: PublisherSiteStatus::from((string) $row['status']),
            verificationToken: (string) ($row['verification_token'] ?? ''),
            verifiedAt: $row['verified_at'] === null ? null : new DateTimeImmutable((string) $row['verified_at']),
            name: (string) ($row['name'] ?? $row['domain']),
        );
    }
}
