<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

final readonly class EmptyAdCandidateRepository implements AdCandidateRepositoryInterface
{
    public function eligibleCandidatesForSlot(int $siteId, int $slotId, ?array $size): array
    {
        return [];
    }
}
