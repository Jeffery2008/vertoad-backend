<?php

declare(strict_types=1);

namespace VertoAD\Tests\Attribution;

use DI\ContainerBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Http\Action\Attribution\ConversionPixelAction;
use VertoAD\Http\Action\Attribution\ServerConversionAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\Attribution\InMemoryAttributionEventRepository;
use VertoAD\Service\Attribution\AttributionService;

final class AttributionRouteIntegrationTest extends TestCase
{
    public function testServerApiConversionRouteReturnsEnvelope(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision(), 'click-1', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $app = $this->createApp($repository);

        $response = $this->handleJson($app, 'POST', '/api/v1/attribution/conversions', [
            'event_id' => 'conv-server-1',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
            'value_points' => 9900,
            'occurred_at' => '2026-06-08T11:00:00+00:00',
        ]);

        self::assertSame(201, $response['status']);
        self::assertArrayHasKey('data', $response['body']);
        self::assertNull($response['body']['error']);
        self::assertSame('server_api', $response['body']['data']['source']);
        self::assertTrue($response['body']['data']['attribution']['attributed']);
        self::assertSame('click-1', $response['body']['data']['attribution']['click_event_id']);
    }

    public function testPixelConversionRouteSupportsQueryContract(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision(), 'click-1', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $app = $this->createApp($repository);

        $response = $this->handleJson(
            $app,
            'GET',
            '/api/v1/attribution/pixel?event_id=conv-pixel-1&viewer_id=viewer-1&conversion_name=signup&value_points=0&occurred_at=2026-06-08T11%3A00%3A00%2B00%3A00&window_seconds=7200',
        );

        self::assertSame(201, $response['status']);
        self::assertSame('browser_pixel', $response['body']['data']['source']);
        self::assertSame(7200, $response['body']['data']['attribution']['window_seconds']);
        self::assertTrue($response['body']['data']['attribution']['attributed']);
    }

    public function testConversionRouteRejectsInvalidPayloadWithEnvelope(): void
    {
        $app = $this->createApp(new InMemoryAttributionEventRepository());

        $response = $this->handleJson($app, 'POST', '/api/v1/attribution/conversions', [
            'event_id' => 'conv-invalid',
            'viewer_id' => 'viewer-1',
            'conversion_name' => '',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_request', $response['body']['error']['code']);
    }

    public function testPixelConversionRouteRejectsInvalidPayloadWithEnvelope(): void
    {
        $app = $this->createApp(new InMemoryAttributionEventRepository());

        $response = $this->handleJson($app, 'GET', '/api/v1/attribution/pixel?event_id=conv-invalid&viewer_id=viewer-1&conversion_name=');

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_request', $response['body']['error']['code']);
    }

    private function createApp(InMemoryAttributionEventRepository $repository): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            AttributionService::class => static fn (): AttributionService => new AttributionService($repository, 604800),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/attribution/conversions', ServerConversionAction::class);
        $app->get('/api/v1/attribution/pixel', ConversionPixelAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>}
     */
    private function handleJson(App $app, string $method, string $uri, ?array $payload = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    private function decision(): AdDecision
    {
        return new AdDecision(
            decisionId: 'decision-1',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            filled: true,
            reason: null,
            iframeHtml: '<iframe></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/landing',
            decidedAt: new DateTimeImmutable('2026-06-08T09:00:00+00:00'),
        );
    }
}
