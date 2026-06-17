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

final readonly class CreateCreativeDesignVersionAction
{
    public function __construct(private CreativeDesignService $service)
    {
    }

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated() || $context->user === null) {
            return CreativeJson::error($response, 401, 'authentication_required', 'Authentication is required for this endpoint.');
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return CreativeJson::error($response, 422, 'invalid_request', 'JSON object payload is required.');
        }

        $queryOrganizationId = CampaignRequestGuards::positiveInteger($request->getQueryParams()['organization_id'] ?? null);
        $bodyOrganizationId = CampaignRequestGuards::positiveInteger($payload['organization_id'] ?? null);
        $organizationId = $queryOrganizationId ?? $bodyOrganizationId;
        if ($organizationId === null) {
            return CreativeJson::error($response, 400, 'organization_scope_required', 'A positive organization_id is required.');
        }

        if ($queryOrganizationId !== null && $bodyOrganizationId !== null && $queryOrganizationId !== $bodyOrganizationId) {
            return CreativeJson::error($response, 403, 'organization_scope_mismatch', 'The request organization scope does not match the payload organization_id.');
        }

        if ($context->organizationId !== null && $context->organizationId !== $organizationId) {
            return CreativeJson::error($response, 403, 'organization_scope_mismatch', 'The request organization scope does not match the requested organization_id.');
        }

        try {
            $version = $this->service->createVersion(
                (string) ($args['design_id'] ?? ''),
                $organizationId,
                $payload,
                $context->user->id,
                RequestIdContext::current(),
            );
        } catch (InvalidArgumentException $exception) {
            return CreativeJson::error($response, 422, 'invalid_request', $exception->getMessage());
        }

        if ($version === null) {
            return CreativeJson::error($response, 404, 'not_found', 'Creative design was not found.');
        }

        return CreativeJson::write($response, $version->toArray(), 201);
    }
}
