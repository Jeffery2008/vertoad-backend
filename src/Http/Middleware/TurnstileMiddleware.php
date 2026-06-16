<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Infrastructure\Security\TurnstileVerifier;
use VertoAD\Service\AuditLogService;

final readonly class TurnstileMiddleware implements MiddlewareInterface
{
    private TurnstilePolicy $policy;

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private TurnstileVerifier $verifier,
        private ?AuditLogService $audit = null,
        private ?ClientIpResolver $ipResolver = null,
        ?TurnstilePolicy $policy = null,
        private bool $allowRuntimeBypass = true,
    ) {
        $this->policy = $policy ?? TurnstilePolicy::default();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $protected = $this->policy->matchesRequest(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $this->isAbnormalTrafficSubmission($request),
        );
        if (!$protected) {
            return $handler->handle($request);
        }

        if (!$this->policy->enabled) {
            if ($this->allowRuntimeBypass) {
                return $handler->handle($request);
            }

            $this->audit($request, 'security.turnstile.denied', 'turnstile_policy_disabled');

            return $this->errorResponse(
                $request,
                503,
                'turnstile_policy_disabled',
                'Turnstile verification is disabled by runtime policy.',
            );
        }

        if (!$this->verifier->isConfigured() && $this->allowRuntimeBypass) {
            return $handler->handle($request);
        }

        $result = $this->verifier->verify($this->token($request), $this->remoteIp($request));
        if ($result->success) {
            $this->audit($request, 'security.turnstile.accepted', $result->code);

            return $handler->handle($request);
        }

        $this->audit($request, 'security.turnstile.denied', $result->code);
        $statusCode = in_array($result->code, ['turnstile_provider_unavailable', 'turnstile_not_configured'], true) ? 503 : 400;

        return $this->errorResponse($request, $statusCode, $result->code, $result->message);
    }

    private function token(ServerRequestInterface $request): ?string
    {
        $header = trim($request->getHeaderLine('CF-Turnstile-Token'));
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return null;
        }

        foreach (['cf_turnstile_token', 'turnstile_token', 'cf-turnstile-response'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string) $body[$key];
            }
        }

        return null;
    }

    private function remoteIp(ServerRequestInterface $request): ?string
    {
        return ($this->ipResolver ?? new ClientIpResolver())->resolve($request);
    }

    private function isAbnormalTrafficSubmission(ServerRequestInterface $request): bool
    {
        foreach ([
            $request->getHeaderLine('X-VertoAD-Traffic-Risk'),
            $request->getHeaderLine('X-VertoAD-Risk'),
            $request->getHeaderLine('X-Traffic-Risk'),
            $this->stringQueryParam($request, 'traffic_risk'),
            $this->stringQueryParam($request, 'risk'),
            $this->stringQueryParam($request, 'abnormal'),
            $this->stringQueryParam($request, 'high_risk'),
            $this->stringBodyParam($request, 'traffic_risk'),
            $this->stringBodyParam($request, 'risk'),
            $this->stringBodyParam($request, 'abnormal'),
            $this->stringBodyParam($request, 'high_risk'),
        ] as $value) {
            if ($this->isAbnormalRiskValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function stringQueryParam(ServerRequestInterface $request, string $key): ?string
    {
        $value = $request->getQueryParams()[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private function stringBodyParam(ServerRequestInterface $request, string $key): ?string
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return null;
        }

        $value = $body[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private function isAbnormalRiskValue(?string $value): bool
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'abnormal', 'high', 'high_risk', 'suspicious', 'challenge_required'], true);
    }

    private function audit(ServerRequestInterface $request, string $action, string $reason): void
    {
        $this->audit?->record(
            action: $action,
            subjectType: 'security_control',
            ipAddress: $this->remoteIp($request),
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            requestId: RequestIdContext::fromRequest($request),
            metadata: [
                'endpoint' => $request->getMethod() . ':' . $request->getUri()->getPath(),
                'reason' => $reason,
            ],
        );
    }

    private function errorResponse(ServerRequestInterface $request, int $statusCode, string $code, string $message): ResponseInterface
    {
        $requestId = RequestIdContext::ensure($request);

        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write(json_encode([
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => [
                'api_version' => 'v1',
            ],
            'request_id' => $requestId,
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-Id', $requestId);
    }
}
