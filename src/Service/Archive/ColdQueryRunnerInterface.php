<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

interface ColdQueryRunnerInterface
{
    public function run(ColdQueryExecutionRequest $request): ColdQueryExecutionResult;
}
