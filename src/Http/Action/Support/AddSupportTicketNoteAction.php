<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Support\SupportTicketService;

final readonly class AddSupportTicketNoteAction
{
    public function __construct(private SupportTicketService $tickets)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $context = RequestUserContext::fromRequest($request);

        try {
            return OperationsJson::write($response, $this->tickets->addInternalNote(
                (string) ($args['ticket_id'] ?? ''),
                $context->user?->id ?? 0,
                (string) ($payload['role'] ?? 'member'),
                (string) ($payload['body'] ?? ''),
            ));
        } catch (\RuntimeException $exception) {
            return OperationsJson::write($response, ['code' => 'forbidden', 'message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }
    }
}
