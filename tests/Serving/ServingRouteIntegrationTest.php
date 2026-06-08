<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Http\Action\Serving\ClickAction;
use VertoAD\Http\Action\Serving\ServeAction;
use VertoAD\Http\Action\Serving\TrackAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Repository\Serving\StaticServingInventoryRepository;
use VertoAD\Service\Serving\AdServingService;

final class ServingRouteIntegrationTest extends TestCase
{
    public function testServeReturnsFilledDecisionEnvelope(): void
    {
        $app = $this->createApp([$this->safeCandidate()]);

        $decoded = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
            'size' => ['width' => 300, 'height' => 250],
            'debug' => true,
        ]);

        self::assertTrue($decoded['data']['filled']);
        self::assertSame('ad-1', $decoded['data']['ad']['id']);
        self::assertSame('https://advertiser.example/landing', $decoded['data']['ad']['landing_url']);
        self::assertStringContainsString('sandbox=', $decoded['data']['iframe']['html']);
        self::assertSame(300, $decoded['data']['iframe']['width']);
        self::assertSame(250, $decoded['data']['iframe']['height']);
        self::assertArrayHasKey('eligibility_reason', $decoded['data']['debug']);
    }

    public function testServeNoFillAndInvalidInputAreControlled(): void
    {
        $app = $this->createApp([]);

        $noFill = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]);
        self::assertFalse($noFill['data']['filled']);
        self::assertSame('no_eligible_ad', $noFill['data']['reason']);

        $invalid = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 0,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]);
        self::assertSame('invalid_request', $invalid['error']['code']);

        $invalidViewer = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => ' ',
        ]);
        self::assertSame('invalid_request', $invalidViewer['error']['code']);

        $invalidSize = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
            'size' => ['width' => 0, 'height' => 250],
        ]);
        self::assertSame('invalid_request', $invalidSize['error']['code']);

        $invalidDebug = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
            'debug' => 'yes',
        ]);
        self::assertSame('invalid_request', $invalidDebug['error']['code']);
    }

    public function testTrackAcceptsValidImpressionAndDeduplicates(): void
    {
        $events = new InMemoryAdEventRepository();
        $app = $this->createApp([$this->safeCandidate()], $events);
        $served = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]);

        $accepted = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-1',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertTrue($accepted['data']['accepted']);
        self::assertFalse($accepted['data']['duplicate']);

        $duplicate = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-1',
            'visible_ratio' => 0.75,
            'visible_ms' => 1500,
        ]);
        self::assertTrue($duplicate['data']['accepted']);
        self::assertTrue($duplicate['data']['duplicate']);
        self::assertSame(1, $events->impressionCount());
    }

    public function testTrackRejectsInvalidThresholdAndClickRedirectsOnlyValidatedUrl(): void
    {
        $app = $this->createApp([$this->safeCandidate()]);
        $served = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]);

        $invalidTrack = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-low',
            'visible_ratio' => 0.49,
            'visible_ms' => 1000,
        ]);
        self::assertSame('viewability_threshold_not_met', $invalidTrack['error']['code']);

        $validTrack = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-valid',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertTrue($validTrack['data']['accepted']);

        $click = $this->handleRaw($app, 'GET', '/api/v1/ads/click?decision_id=' . rawurlencode($served['data']['decision_id']) . '&viewer_id=viewer-1&event_id=clk-1');
        self::assertSame(302, $click->getStatusCode());
        self::assertSame('https://advertiser.example/landing', $click->getHeaderLine('Location'));

        $unsafeApp = $this->createApp([new AdCandidate('ad-2', 30, 40, 'Unsafe', 'http://127.0.0.1/admin', 300, 250, 10, 20)]);
        $unsafeServed = $this->handleJson($unsafeApp, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]);
        self::assertFalse($unsafeServed['data']['filled']);
        self::assertSame('unsafe_landing_url', $unsafeServed['data']['reason']);
    }

    public function testTrackAndClickInvalidInputsAreControlled(): void
    {
        $app = $this->createApp([$this->safeCandidate()]);

        $missingDecisionId = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-invalid',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertSame('invalid_request', $missingDecisionId['error']['code']);

        $invalidVisibleMs = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => 'missing',
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-invalid',
            'visible_ratio' => 0.5,
            'visible_ms' => -1,
        ]);
        self::assertSame('invalid_request', $invalidVisibleMs['error']['code']);

        $invalidVisibleRatioType = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => 'missing',
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-invalid',
            'visible_ratio' => '0.5',
            'visible_ms' => 1000,
        ]);
        self::assertSame('invalid_request', $invalidVisibleRatioType['error']['code']);

        $invalidVisibleRatioRange = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => 'missing',
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-invalid',
            'visible_ratio' => 1.1,
            'visible_ms' => 1000,
        ]);
        self::assertSame('invalid_request', $invalidVisibleRatioRange['error']['code']);

        $unknownDecision = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => 'missing',
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-unknown',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertSame('decision_not_found', $unknownDecision['error']['code']);

        $missingClickParam = $this->handleJson($app, 'GET', '/api/v1/ads/click?viewer_id=viewer-1&event_id=clk-invalid');
        self::assertSame('invalid_request', $missingClickParam['error']['code']);

        $unknownClickDecision = $this->handleJson($app, 'GET', '/api/v1/ads/click?decision_id=missing&viewer_id=viewer-1&event_id=clk-unknown');
        self::assertSame('decision_not_found', $unknownClickDecision['error']['code']);
    }

    /**
     * @param list<AdCandidate> $candidates
     */
    private function createApp(array $candidates, ?InMemoryAdEventRepository $events = null): App
    {
        $decisions = new InMemoryAdDecisionRepository();
        $events ??= new InMemoryAdEventRepository();
        $container = (new ContainerBuilder())->addDefinitions([
            AdServingService::class => static fn (): AdServingService => new AdServingService(
                new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
                new StaticAdCandidateRepository($candidates),
                $decisions,
                $events,
            ),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/ads/serve', ServeAction::class);
        $app->post('/api/v1/ads/track', TrackAction::class);
        $app->get('/api/v1/ads/click', ClickAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(App $app, string $method, string $uri, ?array $payload = null): array
    {
        $response = $this->handleRaw($app, $method, $uri, $payload);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function handleRaw(App $app, string $method, string $uri, ?array $payload = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        return $app->handle($request);
    }

    private function safeCandidate(): AdCandidate
    {
        return new AdCandidate(
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            creativeHtml: '<strong>VertoAD creative</strong>',
            landingUrl: 'https://advertiser.example/landing',
            width: 300,
            height: 250,
            impressionCostPoints: 10,
            clickCostPoints: 20,
        );
    }
}
