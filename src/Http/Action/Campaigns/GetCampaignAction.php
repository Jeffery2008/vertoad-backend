<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Campaigns;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Campaign\CampaignService;
use VertoAD\Service\Campaign\CampaignValidationException;

final readonly class GetCampaignAction
{
    public function __construct(private CampaignService $service)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return CampaignSerializers::json($response, $error['payload'], $error['status']);
        }

        $campaignId = CampaignRequestGuards::positiveInteger($args['campaign_id'] ?? null);
        if ($campaignId === null) {
            return CampaignSerializers::json($response, ['code' => 'invalid_request', 'message' => 'campaign_id must be a positive integer.'], 422);
        }

        try {
            $campaign = $this->service->get((int) $context->organizationId, $campaignId);
        } catch (CampaignValidationException $exception) {
            return CampaignSerializers::json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return CampaignSerializers::json($response, CampaignSerializers::campaign($campaign), 200);
    }
}
