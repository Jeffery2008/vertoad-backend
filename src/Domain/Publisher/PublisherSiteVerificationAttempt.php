<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

use DateTimeImmutable;

final readonly class PublisherSiteVerificationAttempt
{
    public function __construct(
        public ?int $id,
        public int $siteId,
        public int $organizationId,
        public PublisherSiteVerificationMethod $method,
        public string $expectedValue,
        public ?string $observedSummary,
        public PublisherSiteVerificationAttemptStatus $status,
        public ?string $failureReason,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $checkedAt,
    ) {
    }
}
