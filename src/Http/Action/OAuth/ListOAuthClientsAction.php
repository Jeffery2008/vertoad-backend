<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\OAuth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\OAuthClientRepositoryInterface;

final readonly class ListOAuthClientsAction
{
    public function __construct(private OAuthClientRepositoryInterface $clients)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return OAuthClientSerializers::json($response, $guard['payload'], $guard['status']);
        }

        return OAuthClientSerializers::json($response, [
            'clients' => OAuthClientSerializers::clients($this->clients->listActiveForOrganization((int) $context->organizationId)),
        ], 200);
    }
}
