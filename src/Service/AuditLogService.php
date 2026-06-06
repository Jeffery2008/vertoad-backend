<?php

declare(strict_types=1);

namespace VertoAD\Service;

use InvalidArgumentException;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepositoryInterface;

final class AuditLogService
{
    public function __construct(private readonly AuditLogRepositoryInterface $repository)
    {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function record(
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        ?int $actorUserId = null,
        ?int $organizationId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $metadata = null,
    ): void {
        $action = trim($action);
        if ($action === '') {
            throw new InvalidArgumentException('Audit action is required.');
        }

        $subjectType = trim($subjectType);
        if ($subjectType === '') {
            throw new InvalidArgumentException('Audit subject type is required.');
        }

        $packedIpAddress = $this->packIpAddress($ipAddress);

        $this->repository->append(new AuditLogEntry(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actorUserId: $actorUserId,
            organizationId: $organizationId,
            packedIpAddress: $packedIpAddress,
            userAgent: $userAgent === null ? null : trim($userAgent),
            metadata: $this->normalizeMetadata($metadata),
        ));
    }

    private function packIpAddress(?string $ipAddress): ?string
    {
        if ($ipAddress === null || trim($ipAddress) === '') {
            return null;
        }

        $packed = inet_pton(trim($ipAddress));
        if ($packed === false) {
            throw new InvalidArgumentException('Audit IP address is invalid.');
        }

        return $packed;
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>|null
     */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        ksort($metadata);

        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = $this->normalizeMetadata($value);
            }
        }

        return $metadata;
    }
}
