<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Support\SupportTicketService;

final readonly class UpdateSupportTicketStatusAction
{
    public function __construct(private SupportTicketService $tickets)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $context = RequestUserContext::fromRequest($request);

        try {
            return OperationsJson::write($response, $this->tickets->transitionStatus(
                (string) ($args['ticket_id'] ?? ''),
                (string) ($payload['status'] ?? ''),
                $context->user?->id ?? 0,
            ));
        } catch (\RuntimeException) {
            return OperationsJson::write($response, ['code' => 'not_found', 'message' => 'Support ticket not found.'], 404);
        } catch (\InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }
    }
}
