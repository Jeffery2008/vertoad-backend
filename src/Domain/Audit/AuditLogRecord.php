<?php

declare(strict_types=1);

namespace VertoAD\Domain\Audit;

final readonly class AuditLogRecord
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public int $id,
        public ?int $organizationId,
        public ?int $actorUserId,
        public string $action,
        public string $subjectType,
        public ?int $subjectId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $requestId,
        public ?array $metadata,
        public string $createdAt,
    ) {
    }
}
