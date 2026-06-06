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

    public function findById(int $id): ?PublisherSite
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'organization_id', 'domain', 'status', 'verification_token', 'verified_at')
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
        );
    }
}
