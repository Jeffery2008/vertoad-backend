<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Creative;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Creative\CreativeDesignService;

final readonly class ListCreativeDesignVersionsAction
{
    public function __construct(private CreativeDesignService $service)
    {
    }

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated()) {
            return CreativeJson::error($response, 401, 'authentication_required', 'Authentication is required for this endpoint.');
        }

        $organizationId = CampaignRequestGuards::positiveInteger($request->getQueryParams()['organization_id'] ?? null);
        if ($organizationId === null) {
            return CreativeJson::error($response, 400, 'organization_scope_required', 'A positive organization_id query parameter is required.');
        }

        try {
            $result = $this->service->listVersions((string) ($args['design_id'] ?? ''), $organizationId);
        } catch (InvalidArgumentException $exception) {
            return CreativeJson::error($response, 422, 'invalid_request', $exception->getMessage());
        }

        if ($result === null) {
            return CreativeJson::error($response, 404, 'not_found', 'Creative design was not found.');
        }

        return CreativeJson::write($response, [
            'design' => $result['design']->toArray(),
            'versions' => array_map(static fn ($version): array => $version->toArray(), $result['versions']),
        ]);
    }
}
