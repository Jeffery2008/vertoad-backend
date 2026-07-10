<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;

final readonly class EmptyAdCandidateRepository implements AdCandidateRepositoryInterface
{
    public function eligibleCandidatesForSlot(
        int $siteId,
        int $slotId,
        ?array $size,
        ?DateTimeImmutable $now = null,
    ): array {
        return [];
    }
}
