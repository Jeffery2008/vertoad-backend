<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use VertoAD\Domain\Audit\AuditLogRecord;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogQueryRepositoryInterface;
use VertoAD\Repository\AuditLogRepositoryInterface;

final class OperationAuditRepository implements AuditLogRepositoryInterface, AuditLogQueryRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function search(array $filters): array
    {
        $requestId = trim((string) ($filters['request_id'] ?? ''));
        $items = [];

        foreach ($this->entries as $index => $entry) {
            $metadata = $entry->metadata ?? [];
            $entryRequestId = trim((string) ($entry->requestId ?? $metadata['request_id'] ?? $metadata['correlation_id'] ?? ''));
            if ($requestId !== '' && $entryRequestId !== $requestId) {
                continue;
            }

            $items[] = new AuditLogRecord(
                id: $index + 1,
                organizationId: $entry->organizationId,
                actorUserId: $entry->actorUserId,
                action: $entry->action,
                subjectType: $entry->subjectType,
                subjectId: $entry->subjectId,
                ipAddress: $this->unpackIpAddress($entry->packedIpAddress),
                userAgent: $entry->userAgent,
                requestId: $entryRequestId === '' ? null : $entryRequestId,
                metadata: $entry->metadata,
                createdAt: '2026-06-08T13:00:00Z',
            );
        }

        return [
            'items' => $items,
            'limit' => (int) ($filters['limit'] ?? 50),
            'offset' => (int) ($filters['offset'] ?? 0),
            'total' => count($items),
            'has_more' => false,
        ];
    }

    private function unpackIpAddress(?string $packedIpAddress): ?string
    {
        if ($packedIpAddress === null) {
            return null;
        }

        $ipAddress = inet_ntop($packedIpAddress);

        return $ipAddress === false ? null : $ipAddress;
    }
}
