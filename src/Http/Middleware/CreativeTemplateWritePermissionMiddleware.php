<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\TenantAccessService;

final readonly class CreativeTemplateWritePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private TenantAccessService $tenantAccess,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated()) {
            return $this->errorResponse(401, 'authentication_required', 'Authentication is required for this endpoint.');
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return $this->errorResponse(422, 'invalid_request', 'JSON object payload is required.');
        }

        $scope = is_string($payload['scope'] ?? null) ? trim((string) $payload['scope']) : 'organization';
        $permission = $scope === 'platform' ? 'creative.template.manage.platform' : 'creative.template.write.own';

        if ($context->oauthToken !== null && !$context->hasOAuthScope($permission)) {
            return $this->errorResponse(403, 'permission_required', 'The OAuth access token is missing the required scope.', [
                'required_permission' => $permission,
            ]);
        }

        if ($scope === 'platform') {
            if ($context->user?->isSuperAdmin === true) {
                return $handler->handle($request);
            }

            if ($context->user === null || $context->organizationId === null) {
                return $this->errorResponse(403, 'permission_required', 'Platform template management permission is required.', [
                    'required_permission' => $permission,
                ]);
            }

            $decision = $this->tenantAccess->decide($context->user, $context->organizationId, $permission);
            if ($decision->allowed) {
                return $handler->handle($request);
            }

            return $this->errorResponse(403, $decision->reason, 'Platform template management permission is required.', [
                'required_permission' => $permission,
            ]);
        }

        $organizationId = CampaignRequestGuards::positiveInteger($payload['organization_id'] ?? null) ?? $context->organizationId;
        if ($organizationId === null) {
            return $this->errorResponse(400, 'organization_scope_required', 'A positive organization_id is required.', [
                'required_permission' => $permission,
            ]);
        }

        if ($context->oauthToken !== null && $context->oauthToken->organizationId !== $organizationId) {
            return $this->errorResponse(403, 'organization_scope_mismatch', 'The OAuth access token is not scoped to the requested organization.', [
                'required_permission' => $permission,
            ]);
        }

        if ($context->user === null) {
            return $handler->handle($request);
        }

        $decision = $this->tenantAccess->decide($context->user, $organizationId, $permission);
        if ($decision->allowed) {
            return $handler->handle($request);
        }

        return $this->errorResponse(403, $decision->reason, 'The authenticated user is not allowed to manage creative templates.', [
            'required_permission' => $permission,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function errorResponse(int $statusCode, string $code, string $message, array $extra = []): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write(json_encode([
            'code' => $code,
            'message' => $message,
            ...$extra,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
