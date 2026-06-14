<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use VertoAD\Domain\Reporting\ConversionPathJourney;

interface ConversionPathRepositoryInterface
{
    /**
     * @param array<string, mixed> $filters
     * @return list<ConversionPathJourney>
     */
    public function findAttributedJourneys(array $filters): array;
}
