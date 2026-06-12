<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Domain\Security\TurnstilePolicy;
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
        if (!$this->policy->enabled && !$this->allowRuntimeBypass) {
            $this->audit($request, 'security.turnstile.denied', 'turnstile_policy_disabled');

            return $this->errorResponse(
                503,
                'turnstile_policy_disabled',
                'Turnstile verification is disabled by runtime policy.',
            );
        }

        if (!$this->policy->protects($request->getMethod(), $request->getUri()->getPath())) {
            return $handler->handle($request);
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

        return $this->errorResponse($statusCode, $result->code, $result->message);
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

    private function audit(ServerRequestInterface $request, string $action, string $reason): void
    {
        $this->audit?->record(
            action: $action,
            subjectType: 'security_control',
            ipAddress: $this->remoteIp($request),
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            metadata: [
                'endpoint' => $request->getMethod() . ':' . $request->getUri()->getPath(),
                'reason' => $reason,
            ],
        );
    }

    private function errorResponse(int $statusCode, string $code, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write(json_encode(['code' => $code, 'message' => $message], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
