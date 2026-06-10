<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

use InvalidArgumentException;
use RuntimeException;

final readonly class FixtureColdQueryRunner implements ColdQueryRunnerInterface
{
    /**
     * @param array<string, int> $rowCountsBySql
     */
    public function __construct(
        private array $rowCountsBySql = [],
        private ?string $failMessage = null,
    ) {
    }

    public function run(ColdQueryExecutionRequest $request): ColdQueryExecutionResult
    {
        if ($this->failMessage !== null) {
            throw new RuntimeException($this->failMessage);
        }

        $sql = trim($request->sql);
        if (!preg_match('/^select\b/i', $sql) || str_contains(rtrim($sql, ';'), ';')) {
            throw new InvalidArgumentException('Cold query runner only accepts a single SELECT fixture query.');
        }

        return new ColdQueryExecutionResult(
            resultObjectKey: $request->resultObjectKey,
            resultFormat: 'json',
            rowCount: $this->rowCountsBySql[$sql] ?? count($request->objectKeys),
            scannedObjectKeys: $request->objectKeys,
        );
    }
}
