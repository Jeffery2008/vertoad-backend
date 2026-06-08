<?php

declare(strict_types=1);

namespace VertoAD\Repository\Reporting;

use VertoAD\Domain\Reporting\ReportAggregateRow;

final readonly class StaticReportAggregateRepository implements ReportAggregateRepositoryInterface
{
    /**
     * @param list<ReportAggregateRow> $rows
     */
    public function __construct(private array $rows)
    {
    }

    public function query(array $filters): array
    {
        return $this->rows;
    }
}
