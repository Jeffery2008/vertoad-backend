<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttempt;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttemptStatus;
use VertoAD\Domain\Publisher\PublisherSiteVerificationMethod;

final readonly class PublisherSiteVerificationAttemptRepository implements PublisherSiteVerificationAttemptRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(PublisherSiteVerificationAttempt $attempt): PublisherSiteVerificationAttempt
    {
        $this->connection->insert('publisher_site_verification_attempts', [
            'site_id' => $attempt->siteId,
            'organization_id' => $attempt->organizationId,
            'method' => $attempt->method->value,
            'expected_value' => $attempt->expectedValue,
            'observed_summary' => $attempt->observedSummary,
            'status' => $attempt->status->value,
            'failure_reason' => $attempt->failureReason,
            'created_at' => $this->formatDate($attempt->createdAt),
            'checked_at' => $this->formatDate($attempt->checkedAt),
        ]);

        return new PublisherSiteVerificationAttempt(
            id: (int) $this->connection->lastInsertId(),
            siteId: $attempt->siteId,
            organizationId: $attempt->organizationId,
            method: $attempt->method,
            expectedValue: $attempt->expectedValue,
            observedSummary: $attempt->observedSummary,
            status: $attempt->status,
            failureReason: $attempt->failureReason,
            createdAt: $attempt->createdAt,
            checkedAt: $attempt->checkedAt,
        );
    }

    public function listForSite(int $siteId, int $organizationId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'site_id',
                'organization_id',
                'method',
                'expected_value',
                'observed_summary',
                'status',
                'failure_reason',
                'created_at',
                'checked_at',
            )
            ->from('publisher_site_verification_attempts')
            ->where('site_id = :site_id')
            ->andWhere('organization_id = :organization_id')
            ->orderBy('checked_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setParameter('site_id', $siteId)
            ->setParameter('organization_id', $organizationId)
            ->fetchAllAssociative();

        return array_map(fn (array $row): PublisherSiteVerificationAttempt => $this->mapRow($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): PublisherSiteVerificationAttempt
    {
        return new PublisherSiteVerificationAttempt(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            organizationId: (int) $row['organization_id'],
            method: PublisherSiteVerificationMethod::from((string) $row['method']),
            expectedValue: (string) $row['expected_value'],
            observedSummary: $row['observed_summary'] === null ? null : (string) $row['observed_summary'],
            status: PublisherSiteVerificationAttemptStatus::from((string) $row['status']),
            failureReason: $row['failure_reason'] === null ? null : (string) $row['failure_reason'],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            checkedAt: new DateTimeImmutable((string) $row['checked_at'], new DateTimeZone('UTC')),
        );
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
