<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use DateTimeImmutable;
use VertoAD\Domain\Reporting\ReportAggregateRow;

interface ReportAggregateRepositoryInterface
{
    /**
     * @param array{
     *     organization_id?: int,
     *     campaign_id?: int,
     *     site_id?: int,
     *     slot_id?: int,
     *     from?: DateTimeImmutable,
     *     to?: DateTimeImmutable
     * } $filters
     * @return list<ReportAggregateRow>
     */
    public function query(array $filters): array;
}
