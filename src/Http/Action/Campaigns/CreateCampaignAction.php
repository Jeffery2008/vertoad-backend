<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Campaigns;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Campaign\CampaignService;
use VertoAD\Service\Campaign\CampaignValidationException;

final readonly class CreateCampaignAction
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return CampaignSerializers::json($response, ['code' => 'invalid_request', 'message' => 'JSON object body is required.'], 422);
        }

        try {
            $campaign = $this->service->create((int) $context->organizationId, $body);
        } catch (CampaignValidationException $exception) {
            return CampaignSerializers::json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return CampaignSerializers::json($response, CampaignSerializers::campaign($campaign), 201);
    }
}
