<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;

final readonly class MeAction
{
    public function __construct(private OrganizationMembershipRepositoryInterface $memberships)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'Authentication is required for this endpoint.',
            ], 401);
        }

        $organizationId = $context->organizationId;
        $membership = $organizationId === null
            ? null
            : $this->memberships->findActiveMembership($context->user->id, $organizationId);
        $organizations = $this->memberships->listActiveOrganizationsForUser($context->user->id);

        return $this->json($response, [
            'user' => [
                'id' => $context->user->id,
                'email' => $context->user->email,
                'is_super_admin' => $context->user->isSuperAdmin,
            ],
            'organization_id' => $organizationId,
            'membership' => $membership === null ? null : [
                'roles' => $membership->roleSlugs,
                'permissions' => $membership->permissions,
            ],
            'organizations' => $organizations,
        ], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
