<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Http\Middleware\IpGeoMiddleware;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Service\IpGeo\AsyncIpGeoResolver;

final class IpGeoMiddlewareTest extends TestCase
{
    public function testMiddlewareQueuesTrustedCloudflareIpAndSetsContextFromResolvedCacheOnly(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $middleware = new IpGeoMiddleware(
            new ResponseFactory(),
            new ClientIpResolver('CF-Connecting-IP', ['203.0.113.9']),
            new AsyncIpGeoResolver($repository, 'serving'),
            [],
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/ads/serve', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('CF-Connecting-IP', '198.51.100.10')
            ->withHeader('User-Agent', 'Geo test browser');

        $first = $middleware->process($request, new ContextEchoHandler());
        $firstPayload = json_decode((string) $first->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($firstPayload['geo_code']);
        self::assertCount(1, $repository->rows());

        $repository->markResolved(new GeoIpRecord(
            ipAddress: '198.51.100.10',
            countryCode: 'CN',
            countryName: 'China',
            regionCode: 'SH',
            regionName: 'Shanghai',
            cityName: null,
            latitude: 31.2304,
            longitude: 121.4737,
            timezone: 'Asia/Shanghai',
            providerId: 'ip-sb',
            resolvedAt: new DateTimeImmutable('2026-06-16T00:00:00+00:00'),
            rawPayloadHash: hash('sha256', '{"country_code":"CN"}'),
            rawPayloadSummary: ['country_code' => 'CN'],
        ));

        $second = $middleware->process($request, new ContextEchoHandler());
        $secondPayload = json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('CN-SH', $secondPayload['geo_code']);
        self::assertSame('198.51.100.10', $secondPayload['ip_address']);
    }

    public function testMiddlewareSkipsNonServingEndpointsByDefault(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $middleware = new IpGeoMiddleware(
            new ResponseFactory(),
            new ClientIpResolver('CF-Connecting-IP', ['203.0.113.9']),
            new AsyncIpGeoResolver($repository, 'serving'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/me', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('CF-Connecting-IP', '198.51.100.10');

        $response = $middleware->process($request, new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                TestCase::assertNull($request->getAttribute(ServingRequestContext::class));

                return (new ResponseFactory())->createResponse(204);
            }
        });

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([], $repository->rows());
    }
}

final class ContextEchoHandler implements \Psr\Http\Server\RequestHandlerInterface
{
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $context = $request->getAttribute(ServingRequestContext::class);
        TestCase::assertInstanceOf(ServingRequestContext::class, $context);

        $response = (new ResponseFactory())->createResponse(200)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode([
            'ip_address' => $context->ipAddress,
            'geo_code' => $context->geoCode,
        ], JSON_THROW_ON_ERROR));

        return $response;
    }
}
