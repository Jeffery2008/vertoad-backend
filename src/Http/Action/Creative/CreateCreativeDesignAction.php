<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Creative;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\Creative\CreativeDesignService;

final readonly class CreateCreativeDesignAction
{
    public function __construct(private CreativeDesignService $service)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated() || $context->user === null) {
            return CreativeJson::error($response, 401, 'authentication_required', 'Authentication is required for this endpoint.');
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return CreativeJson::error($response, 422, 'invalid_request', 'JSON object payload is required.');
        }

        $bodyOrganizationId = CampaignRequestGuards::positiveInteger($payload['organization_id'] ?? null);
        if ($bodyOrganizationId === null) {
            return CreativeJson::error($response, 400, 'organization_scope_required', 'A positive organization_id is required.');
        }

        if ($context->organizationId !== null && $context->organizationId !== $bodyOrganizationId) {
            return CreativeJson::error($response, 403, 'organization_scope_mismatch', 'The request organization scope does not match the payload organization_id.');
        }

        try {
            $created = $this->service->createDesign($payload, $context->user->id, RequestIdContext::current());
        } catch (InvalidArgumentException $exception) {
            return CreativeJson::error($response, 422, 'invalid_request', $exception->getMessage());
        }

        return CreativeJson::write($response, [
            'design' => $created['design']->toArray(),
            'version' => $created['version']->toArray(),
        ], 201);
    }
}
