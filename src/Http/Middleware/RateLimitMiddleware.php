<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use DateTimeImmutable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Infrastructure\Security\RateLimitDimensions;
use VertoAD\Infrastructure\Security\RateLimiter;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Service\AuditLogService;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private RateLimiter $limiter,
        private RateLimitPolicy $policy,
        private ?AuditLogService $audit = null,
        private mixed $clock = null,
        private ?ClientIpResolver $ipResolver = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dimensions = $this->dimensions($request);
        $result = $this->limiter->hit($dimensions, $this->policy, $this->now());

        if ($result->allowed) {
            return $handler->handle($request)
                ->withHeader('X-RateLimit-Limit', (string) $result->limit)
                ->withHeader('X-RateLimit-Remaining', (string) $result->remaining)
                ->withHeader('X-RateLimit-Reset', (string) $result->resetAt);
        }

        $this->audit?->record(
            action: 'security.rate_limit.denied',
            subjectType: 'security_control',
            actorUserId: $dimensions->userId,
            organizationId: $dimensions->organizationId,
            ipAddress: $dimensions->ip,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            requestId: RequestIdContext::fromRequest($request),
            metadata: [
                ...$dimensions->auditMetadata(),
                'limit' => $result->limit,
                'retry_after_seconds' => $result->retryAfterSeconds,
            ],
        );

        $response = $this->responseFactory->createResponse(429);
        $response->getBody()->write(json_encode([
            'code' => 'rate_limited',
            'message' => 'Too many requests. Retry after the indicated delay.',
            'retry_after_seconds' => $result->retryAfterSeconds,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $result->retryAfterSeconds)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', '0')
            ->withHeader('X-RateLimit-Reset', (string) $result->resetAt);
    }

    private function dimensions(ServerRequestInterface $request): RateLimitDimensions
    {
        $context = RequestUserContext::fromRequest($request);
        $clientId = $request->getHeaderLine('X-OAuth-Client-Id') ?: $request->getHeaderLine('X-Client-Id');

        return new RateLimitDimensions(
            ip: $this->ipResolver()->resolve($request),
            userId: $context->user?->id,
            organizationId: $context->organizationId ?? $this->organizationId($request),
            clientId: $clientId === '' ? null : $clientId,
            endpoint: $request->getMethod() . ':' . $request->getUri()->getPath(),
        );
    }

    private function now(): DateTimeImmutable
    {
        if (is_callable($this->clock)) {
            $now = ($this->clock)();
            if ($now instanceof DateTimeImmutable) {
                return $now;
            }
        }

        return new DateTimeImmutable();
    }

    private function ipResolver(): ClientIpResolver
    {
        return $this->ipResolver ?? new ClientIpResolver();
    }

    private function organizationId(ServerRequestInterface $request): ?int
    {
        $query = $request->getQueryParams();
        $value = $query['organization_id'] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
