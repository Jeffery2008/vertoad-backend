<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Reporting\ReportAggregateRow;
use VertoAD\Http\Action\Reporting\ReportDashboardAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\Reporting\StaticReportAggregateRepository;
use VertoAD\Service\Reporting\ReportQueryService;

final class ReportDashboardActionTest extends TestCase
{
    public function testDashboardRouteReturnsStandardEnvelopeAndFilters(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/reports/dashboard?portal=advertiser&organization_id=99&campaign_id=123&site_id=5&slot_id=10&from=2026-06-08T00:00:00%2B00:00&to=2026-06-09T00:00:00%2B00:00&granularity=hour',
        );

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertArrayHasKey('meta', $decoded);
        self::assertArrayHasKey('request_id', $decoded);
        self::assertNull($decoded['error']);
        self::assertSame('advertiser', $decoded['data']['portal']);
        self::assertSame('hour', $decoded['data']['range']['granularity']);
        self::assertSame(1, $decoded['data']['totals']['impressions']);
        self::assertSame(1, $decoded['data']['totals']['clicks']);
        self::assertEqualsWithDelta(100.0, $decoded['data']['totals']['ctr'], 0.0001);
    }

    public function testDashboardRouteRejectsInvalidFilters(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reports/dashboard?organization_id=0');

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $decoded['error']['code']);
    }

    public function testDashboardRouteUsesDefaultsWhenOptionalFiltersAreAbsent(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/reports/dashboard');

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin', $decoded['data']['portal']);
        self::assertSame('day', $decoded['data']['range']['granularity']);
    }

    public function testDashboardRouteRejectsNonScalarDateFilter(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/dashboard')
            ->withQueryParams(['from' => ['2026-06-08T00:00:00+00:00']]);

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $decoded['error']['code']);
        self::assertSame('from must be an ISO-8601 date-time string.', $decoded['error']['message']);
    }

    public function testDashboardRouteRejectsInvalidPortalAndGranularity(): void
    {
        $app = $this->createApp();

        foreach ([
            '/api/v1/reports/dashboard?portal=operator',
            '/api/v1/reports/dashboard?granularity=week',
            '/api/v1/reports/dashboard?from=2026-06-09T00:00:00%2B00:00&to=2026-06-08T00:00:00%2B00:00',
            '/api/v1/reports/dashboard?from=not-a-date',
        ] as $uri) {
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $uri));
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(422, $response->getStatusCode(), $uri);
            self::assertSame('invalid_request', $decoded['error']['code']);
        }
    }

    private function createApp(): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            ReportQueryService::class => static fn (): ReportQueryService => new ReportQueryService(
                new StaticReportAggregateRepository([
                    new ReportAggregateRow('2026-06-08', 99, 123, 5, 10, null, null, null, null, null, 1, 1, 40, 24),
                ]),
            ),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->get('/api/v1/reports/dashboard', ReportDashboardAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }
}
