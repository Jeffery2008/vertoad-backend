<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Operations\OperationsSummaryService;

final readonly class OperationsSummaryAction
{
    public function __construct(private OperationsSummaryService $operations)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return OperationsJson::write($response, $this->operations->summary());
    }
}
