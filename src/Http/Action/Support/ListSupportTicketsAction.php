<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Support\SupportTicketService;

final readonly class ListSupportTicketsAction
{
    public function __construct(private SupportTicketService $tickets)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $roles = $request->getQueryParams()['roles'] ?? ['member'];
        $roles = is_array($roles) ? array_values(array_map('strval', $roles)) : [(string) $roles];

        return OperationsJson::write($response, [
            'tickets' => $this->tickets->listVisibleTickets([
                'user_id' => $context->user?->id ?? 0,
                'organization_id' => $context->organizationId,
                'roles' => $roles,
            ]),
        ]);
    }
}
