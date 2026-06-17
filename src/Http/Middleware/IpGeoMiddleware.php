<?php

declare(strict_types=1);

namespace VertoAD\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Service\IpGeo\IpGeoRequestContextResolverInterface;

final readonly class IpGeoMiddleware implements MiddlewareInterface
{
    /** @var array<string, bool> */
    private array $enabledEndpoints;

    /**
     * @param list<string> $enabledEndpoints
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private ClientIpResolver $ipResolver,
        private IpGeoRequestContextResolverInterface $geoResolver,
        array $enabledEndpoints = [
            'GET:/api/v1/ads/serve',
            'POST:/api/v1/ads/serve',
            'POST:/api/v1/ads/track',
            'GET:/api/v1/ads/click',
        ],
    ) {
        $this->enabledEndpoints = array_fill_keys(array_map(
            static fn (string $endpoint): string => strtoupper(trim($endpoint)),
            $enabledEndpoints,
        ), true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->enabledEndpoints !== [] && !$this->shouldApply($request)) {
            return $handler->handle($request);
        }

        $ipAddress = $this->ipResolver->resolve($request);
        $userAgent = trim($request->getHeaderLine('User-Agent')) ?: null;
        $regionHint = trim($request->getHeaderLine('CF-IPCountry')) ?: null;
        $context = $this->geoResolver->contextForIpGeoRequest(
            $ipAddress,
            $userAgent,
            $regionHint,
            RequestIdContext::fromRequest($request),
        );
        $context = new ServingRequestContext(
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            geoCode: $context->geoCode,
            geoRecord: $context->geoRecord,
            requestId: $context->requestId,
            endpoint: '/' . ltrim($request->getUri()->getPath(), '/'),
            httpMethod: strtoupper($request->getMethod()),
        );

        return $handler->handle($request->withAttribute(ServingRequestContext::class, $context));
    }

    private function shouldApply(ServerRequestInterface $request): bool
    {
        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        $key = strtoupper($request->getMethod()) . ':' . $path;

        return isset($this->enabledEndpoints[$key]);
    }
}
