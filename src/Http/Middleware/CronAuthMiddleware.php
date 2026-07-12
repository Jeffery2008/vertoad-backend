<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Infrastructure\Security\ClientIpResolver;

final class CronAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly array $settings,
        private readonly ?ClientIpResolver $ipResolver = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expectedToken = (string) ($this->settings['cron']['token'] ?? '');
        if (array_key_exists('token', $request->getQueryParams())) {
            return $this->errorResponse(401, 'unauthorized', 'Cron token is missing or invalid.');
        }

        $providedToken = $request->getHeaderLine('X-Cron-Token');

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return $this->errorResponse(401, 'unauthorized', 'Cron token is missing or invalid.');
        }

        $allowedIps = $this->settings['cron']['allowed_ips'] ?? [];
        $remoteIp = (string) ($this->ipResolver ?? ClientIpResolver::fromSettings($this->settings['cloudflare'] ?? []))->resolve($request);
        if (is_array($allowedIps) && $allowedIps !== [] && !in_array($remoteIp, $allowedIps, true)) {
            return $this->errorResponse(403, 'forbidden', 'Cron caller IP is not allowed.');
        }

        return $handler->handle($request);
    }

    private function errorResponse(int $statusCode, string $code, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write(json_encode([
            'code' => $code,
            'message' => $message,
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
