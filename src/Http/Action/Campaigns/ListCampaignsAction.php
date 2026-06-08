<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Campaigns;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Campaign\CampaignService;

final readonly class ListCampaignsAction
{
    public function __construct(private CampaignService $service)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return CampaignSerializers::json($response, $error['payload'], $error['status']);
        }

        return CampaignSerializers::json(
            $response,
            CampaignSerializers::campaigns($this->service->list((int) $context->organizationId)),
            200,
        );
    }
}
