<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Operations\OperationRequestCorrelationService;

final readonly class GetOperationRequestCorrelationsAction
{
    public function __construct(private OperationRequestCorrelationService $correlations)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $filters = $request->getQueryParams();
        if (isset($args['request_id']) && trim((string) $args['request_id']) !== '') {
            $filters['request_id'] = trim((string) $args['request_id']);
        }

        try {
            $context = RequestUserContext::fromRequest($request);
            $payload = isset($args['request_id']) && trim((string) $args['request_id']) !== ''
                ? $this->correlations->find(trim((string) $args['request_id']), $context)
                : $this->correlations->search($filters, $context);

            return OperationsJson::write($response, $payload);
        } catch (InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }
    }
}
