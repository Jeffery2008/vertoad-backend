<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Audit\AuditLogRecord;

final class AuditLogRepository implements AuditLogRepositoryInterface, AuditLogQueryRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function append(AuditLogEntry $entry): void
    {
        $this->connection->insert(
            'audit_logs',
            [
                'organization_id' => $entry->organizationId,
                'actor_user_id' => $entry->actorUserId,
                'action' => $entry->action,
                'subject_type' => $entry->subjectType,
                'subject_id' => $entry->subjectId,
                'ip_address' => $entry->packedIpAddress,
                'user_agent' => $entry->userAgent,
                'metadata_json' => $entry->metadata === null
                    ? null
                    : json_encode($entry->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
            [
                'organization_id' => $entry->organizationId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'actor_user_id' => $entry->actorUserId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'action' => ParameterType::STRING,
                'subject_type' => ParameterType::STRING,
                'subject_id' => $entry->subjectId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'ip_address' => $entry->packedIpAddress === null ? ParameterType::NULL : ParameterType::BINARY,
                'user_agent' => $entry->userAgent === null ? ParameterType::NULL : ParameterType::STRING,
                'metadata_json' => $entry->metadata === null ? ParameterType::NULL : ParameterType::STRING,
            ],
        );
    }

    public function search(array $filters): array
    {
        $limit = (int) $filters['limit'];
        $offset = (int) $filters['offset'];

        $countQuery = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('audit_logs', 'al');
        $this->applyFilters($countQuery, $filters);
        $total = (int) $countQuery->fetchOne();

        $listQuery = $this->connection->createQueryBuilder()
            ->select(
                'al.id',
                'al.organization_id',
                'al.actor_user_id',
                'al.action',
                'al.subject_type',
                'al.subject_id',
                'al.ip_address',
                'al.user_agent',
                'al.metadata_json',
                'al.created_at',
            )
            ->from('audit_logs', 'al');
        $this->applyFilters($listQuery, $filters);
        $rows = $listQuery
            ->orderBy('al.created_at', 'DESC')
            ->addOrderBy('al.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->fetchAllAssociative();

        return [
            'items' => array_map(fn (array $row): AuditLogRecord => $this->hydrate($row), $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyFilters(\Doctrine\DBAL\Query\QueryBuilder $query, array $filters): void
    {
        if (isset($filters['action'])) {
            $query->andWhere('al.action = :action')
                ->setParameter('action', $filters['action'], ParameterType::STRING);
        }

        if (isset($filters['organization_id'])) {
            $query->andWhere('al.organization_id = :organization_id')
                ->setParameter('organization_id', $filters['organization_id'], ParameterType::INTEGER);
        }

        if (isset($filters['actor_user_id'])) {
            $query->andWhere('al.actor_user_id = :actor_user_id')
                ->setParameter('actor_user_id', $filters['actor_user_id'], ParameterType::INTEGER);
        }

        if (isset($filters['subject_type'])) {
            $query->andWhere('al.subject_type = :subject_type')
                ->setParameter('subject_type', $filters['subject_type'], ParameterType::STRING);
        }

        if (isset($filters['subject_id'])) {
            $query->andWhere('al.subject_id = :subject_id')
                ->setParameter('subject_id', $filters['subject_id'], ParameterType::INTEGER);
        }

        if (isset($filters['created_from'])) {
            $query->andWhere('al.created_at >= :created_from')
                ->setParameter('created_from', $filters['created_from'], ParameterType::STRING);
        }

        if (isset($filters['created_to'])) {
            $query->andWhere('al.created_at <= :created_to')
                ->setParameter('created_to', $filters['created_to'], ParameterType::STRING);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditLogRecord
    {
        return new AuditLogRecord(
            id: (int) $row['id'],
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            actorUserId: $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            action: (string) $row['action'],
            subjectType: (string) $row['subject_type'],
            subjectId: $row['subject_id'] === null ? null : (int) $row['subject_id'],
            ipAddress: $this->unpackIpAddress($row['ip_address'] ?? null),
            userAgent: $row['user_agent'] === null ? null : (string) $row['user_agent'],
            metadata: $this->decodeMetadata($row['metadata_json'] ?? null),
            createdAt: $this->formatCreatedAt((string) $row['created_at']),
        );
    }

    private function unpackIpAddress(mixed $packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        $ipAddress = @inet_ntop((string) $packed);

        return $ipAddress === false ? null : $ipAddress;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeMetadata(mixed $metadataJson): ?array
    {
        if ($metadataJson === null || $metadataJson === '') {
            return null;
        }

        $decoded = json_decode((string) $metadataJson, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    private function formatCreatedAt(string $createdAt): string
    {
        return str_replace(' ', 'T', $createdAt) . (str_contains($createdAt, '+') || str_ends_with($createdAt, 'Z') ? '' : 'Z');
    }
}
