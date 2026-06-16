<?php

declare(strict_types=1);

namespace VertoAD\Domain\Audit;

final readonly class AuditLogEntry
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $action,
        public string $subjectType,
        public ?int $subjectId,
        public ?int $actorUserId,
        public ?int $organizationId,
        public ?string $packedIpAddress,
        public ?string $userAgent,
        public ?string $requestId = null,
        public ?array $metadata = null,
    ) {
    }
}
