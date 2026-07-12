<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

final readonly class ServingLoadBatchResult
{
    /** @param list<mixed> $outputs */
    public function __construct(
        public ServingLoadStageMetrics $metrics,
        public array $outputs,
    ) {
    }
}
