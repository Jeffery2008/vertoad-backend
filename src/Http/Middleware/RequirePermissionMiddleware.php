<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\TenantAccessService;

final readonly class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private TenantAccessService $tenantAccess,
        private PermissionRequirement $requirement,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if (!$context->isAuthenticated()) {
            return $this->errorResponse(
                401,
                'authentication_required',
                'Authentication is required for this endpoint.',
            );
        }

        if ($context->oauthToken !== null && !$context->hasOAuthScope($this->requirement->permission)) {
            return $this->errorResponse(
                403,
                'permission_required',
                'The OAuth access token is missing the required scope.',
                ['required_permission' => $this->requirement->permission],
            );
        }

        if ($this->requirement->platform) {
            if ($context->user?->isSuperAdmin === true) {
                return $handler->handle($request);
            }

            if ($context->user === null) {
                return $this->errorResponse(
                    403,
                    'permission_required',
                    'The authenticated OAuth client is not allowed to access this platform endpoint.',
                    ['required_permission' => $this->requirement->permission],
                );
            }

            if ($context->organizationId === null) {
                return $this->errorResponse(
                    400,
                    'organization_scope_required',
                    'An organization scope is required for this endpoint.',
                    ['required_permission' => $this->requirement->permission],
                );
            }

            $decision = $this->tenantAccess->decide($context->user, $context->organizationId, $this->requirement->permission);
            if ($decision->allowed) {
                return $handler->handle($request);
            }

            return $this->errorResponse(
                403,
                $decision->reason,
                'The authenticated user is not allowed to access this platform endpoint.',
                ['required_permission' => $this->requirement->permission],
            );
        }

        $organizationId = $this->resolveOrganizationId($request, $context);
        if ($organizationId === null) {
            return $this->errorResponse(
                400,
                'organization_scope_required',
                'An organization scope is required for this endpoint.',
                ['required_permission' => $this->requirement->permission],
            );
        }

        if ($context->oauthToken !== null && $context->oauthToken->organizationId !== $organizationId) {
            return $this->errorResponse(
                403,
                'organization_scope_mismatch',
                'The OAuth access token is not scoped to the requested organization.',
                ['required_permission' => $this->requirement->permission],
            );
        }

        if ($context->user === null) {
            return $handler->handle($request);
        }

        $decision = $this->tenantAccess->decide($context->user, $organizationId, $this->requirement->permission);
        if (!$decision->allowed) {
            return $this->errorResponse(
                403,
                $decision->reason,
                'The authenticated user is not allowed to access this organization scope.',
                ['required_permission' => $this->requirement->permission],
            );
        }

        return $handler->handle($request);
    }

    private function resolveOrganizationId(ServerRequestInterface $request, RequestUserContext $context): ?int
    {
        $routeValue = $request->getAttribute($this->requirement->organizationIdAttribute);
        if (is_int($routeValue)) {
            return $routeValue;
        }

        if (is_string($routeValue) && ctype_digit($routeValue)) {
            return (int) $routeValue;
        }

        $routeArgument = RouteContext::fromRequest($request)->getRoute()?->getArgument($this->requirement->organizationIdAttribute);
        if (is_string($routeArgument) && ctype_digit($routeArgument)) {
            return (int) $routeArgument;
        }

        return $context->organizationId;
    }

    /**
     * @param array<string, mixed> $extra
     */
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
