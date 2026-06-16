<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Operations\OperationErrorCaptureService;

final readonly class ListOperationErrorsAction
{
    public function __construct(private OperationErrorCaptureService $errors)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $requestId = $request->getQueryParams()['request_id'] ?? null;

        return OperationsJson::write($response, ['errors' => $this->errors->listErrors(is_string($requestId) ? $requestId : null)]);
    }
}
