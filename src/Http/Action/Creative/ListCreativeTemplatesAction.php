<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Creative;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Creative\CreativeDesignService;

final readonly class ListCreativeTemplatesAction
{
    public function __construct(private CreativeDesignService $service)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated()) {
            return CreativeJson::error($response, 401, 'authentication_required', 'Authentication is required for this endpoint.');
        }

        $query = $request->getQueryParams();
        $organizationId = CampaignRequestGuards::positiveInteger($query['organization_id'] ?? null);
        if ($organizationId === null) {
            return CreativeJson::error($response, 400, 'organization_scope_required', 'A positive organization_id query parameter is required.');
        }

        try {
            $templates = $this->service->listTemplates($organizationId, is_string($query['scope'] ?? null) ? $query['scope'] : 'all');
        } catch (InvalidArgumentException $exception) {
            return CreativeJson::error($response, 422, 'invalid_request', $exception->getMessage());
        }

        return CreativeJson::write($response, [
            'templates' => array_map(static fn ($template): array => $template->toArray(), $templates),
        ]);
    }
}
