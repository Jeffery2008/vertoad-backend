<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Creative;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\Creative\CreativeDesignService;

final readonly class CreateCreativeTemplateAction
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

        if (($payload['scope'] ?? 'organization') !== 'platform' && $context->organizationId === null) {
            return CreativeJson::error($response, 400, 'organization_scope_required', 'An organization scope is required for this endpoint.');
        }

        try {
            $template = $this->service->createTemplate($payload, $context->user->id, RequestIdContext::current());
        } catch (InvalidArgumentException $exception) {
            return CreativeJson::error($response, 422, 'invalid_request', $exception->getMessage());
        }

        return CreativeJson::write($response, $template->toArray(), 201);
    }
}
