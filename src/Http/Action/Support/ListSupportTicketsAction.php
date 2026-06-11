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
        $query = $request->getQueryParams();
        $roles = $query['roles'] ?? ['member'];
        $roles = is_array($roles) ? array_values(array_map('strval', $roles)) : [(string) $roles];
        $organizationFilterId = null;

        if (array_key_exists('organization_id', $query)) {
            $organizationFilterId = $this->positiveIntegerQuery($query['organization_id']);
            if ($organizationFilterId === null) {
                return OperationsJson::write($response, [
                    'code' => 'invalid_request',
                    'message' => 'organization_id must be a positive integer query parameter.',
                ], 422);
            }
        }

        return OperationsJson::write($response, [
            'tickets' => $this->tickets->listVisibleTickets([
                'user_id' => $context->user?->id ?? 0,
                'organization_id' => $context->organizationId,
                'roles' => $roles,
                'filter_organization_id' => $organizationFilterId,
            ]),
        ]);
    }

    private function positiveIntegerQuery(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
