<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Support;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Support\SupportTicketService;

final readonly class CreateSupportTicketAction
{
    public function __construct(private SupportTicketService $tickets)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $context = RequestUserContext::fromRequest($request);
        $payload['organization_id'] ??= $context->organizationId;
        $payload['created_by_user_id'] ??= $context->user?->id;

        try {
            return OperationsJson::write($response, $this->tickets->createTicket($payload), 201);
        } catch (InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }
    }
}
