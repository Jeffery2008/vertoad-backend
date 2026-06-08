<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Operations\OperationErrorCaptureService;

final readonly class GetRawOperationErrorContextAction
{
    public function __construct(private OperationErrorCaptureService $errors)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $raw = $this->errors->rawContextFor($args['error_id'] ?? '', RequestUserContext::fromRequest($request));
        } catch (RuntimeException $exception) {
            return OperationsJson::write($response, ['code' => 'forbidden', 'message' => $exception->getMessage()], 403);
        }

        return OperationsJson::write($response, ['raw_context' => $raw, 'audit_on_view' => true]);
    }
}
