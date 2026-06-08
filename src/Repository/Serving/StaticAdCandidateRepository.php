<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use VertoAD\Domain\Serving\AdCandidate;

final readonly class StaticAdCandidateRepository implements AdCandidateRepositoryInterface
{
    /**
     * @param list<AdCandidate> $candidates
     */
    public function __construct(private array $candidates)
    {
    }

    public function eligibleCandidatesForSlot(int $siteId, int $slotId, ?array $size): array
    {
        if ($size === null) {
            return $this->candidates;
        }

        return array_values(array_filter(
            $this->candidates,
            static fn (AdCandidate $candidate): bool =>
                $candidate->width === (int) $size['width'] && $candidate->height === (int) $size['height'],
        ));
    }
}
