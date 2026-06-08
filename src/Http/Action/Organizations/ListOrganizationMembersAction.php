<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Organizations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;

final readonly class ListOrganizationMembersAction
{
    public function __construct(private OrganizationMembershipRepositoryInterface $memberships)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $organizationId = CampaignRequestGuards::positiveInteger($args['organization_id'] ?? null);
        if ($organizationId === null) {
            return OrganizationMemberSerializers::json($response, [
                'code' => 'invalid_organization',
                'message' => 'A positive organization_id path parameter is required.',
            ], 400);
        }

        if ($context->user === null) {
            return OrganizationMemberSerializers::json($response, [
                'code' => 'authentication_required',
                'message' => 'Authentication is required for this endpoint.',
            ], 401);
        }

        return OrganizationMemberSerializers::json($response, [
            'members' => $this->memberships->listForOrganization($organizationId),
        ], 200);
    }
}
