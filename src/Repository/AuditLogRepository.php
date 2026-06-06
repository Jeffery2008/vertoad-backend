<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Audit\AuditLogEntry;

final class AuditLogRepository implements AuditLogRepositoryInterface
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
}
