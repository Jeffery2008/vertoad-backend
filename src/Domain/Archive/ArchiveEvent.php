<?php

declare(strict_types=1);

namespace VertoAD\Domain\Archive;

use DateTimeImmutable;

final readonly class ArchiveEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventType,
        public string $eventId,
        public DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
    }
}
