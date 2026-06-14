<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DI\ContainerBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Http\Action\Serving\ClickAction;
use VertoAD\Http\Action\Serving\ServeAction;
use VertoAD\Http\Action\Serving\ServeFrameAction;
use VertoAD\Http\Action\Serving\TrackAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\TurnstileMiddleware;
use VertoAD\Infrastructure\Security\TurnstileVerifier;
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
        self::assertArrayNotHasKey('landing_url', $decoded['data']['ad']);
        self::assertSame(
            '/api/v1/ads/click?decision_id=' . rawurlencode((string) $decoded['data']['decision_id']) . '&viewer_id=viewer-1&event_id={event_id}',
            $decoded['data']['ad']['click_url'],
        );
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

    public function testServeFrameReturnsSandboxedHtmlForSdkIframe(): void
    {
        $app = $this->createApp([$this->safeCandidate()]);

        $response = $this->handleRaw(
            $app,
            'GET',
            '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1&width=300&height=250&debug=1'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts', $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("script-src 'nonce-", $response->getHeaderLine('Content-Security-Policy'));
        $html = (string) $response->getBody();
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('data-vertoad-renderer="platform-controlled"', $html);
        self::assertStringContainsString('data-vertoad-fallback="snapshot"', $html);
        self::assertStringContainsString('organizations/40/assets/creative.png', $html);
        self::assertStringContainsString('data-vertoad-runtime', $html);
        self::assertStringContainsString('/api/v1/ads/track', $html);
        self::assertStringContainsString('/api/v1/ads/click?decision_id=', $html);
        self::assertStringContainsString('protocol: "vertoad"', $html);
        self::assertStringContainsString('impression_eligible', $html);
        self::assertStringContainsString('impression_tracked', $html);
        self::assertStringContainsString('click_requested', $html);

        $invalidResponse = $this->handleRaw($app, 'GET', '/api/v1/ads/serve?site_id=0&slot_id=20&viewer_id=viewer-1');
        self::assertSame(422, $invalidResponse->getStatusCode());
        $invalid = json_decode((string) $invalidResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($invalid);
        self::assertSame('invalid_request', $invalid['error']['code']);
    }

    public function testServeFrameHandlesNoSizeAndInvalidQueryValues(): void
    {
        $app = $this->createApp([$this->safeCandidate()]);

        $noSize = $this->handleRaw($app, 'GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1&debug=false');
        self::assertSame(200, $noSize->getStatusCode());
        self::assertStringContainsString(
            'data-vertoad-renderer="platform-controlled"',
            (string) $noSize->getBody()
        );

        $defaultDebug = $this->handleRaw($app, 'GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1');
        self::assertSame(200, $defaultDebug->getStatusCode());
        self::assertStringContainsString(
            'data-vertoad-fallback="snapshot"',
            (string) $defaultDebug->getBody()
        );

        $invalidViewer = $this->handleJson($app, 'GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=');
        self::assertSame('invalid_request', $invalidViewer['error']['code']);

        $partialSize = $this->handleJson($app, 'GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1&width=300');
        self::assertSame('invalid_request', $partialSize['error']['code']);

        $invalidSize = $this->handleJson($app, 'GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1&width=300&height=0');
        self::assertSame('invalid_request', $invalidSize['error']['code']);

        $invalidDebug = $this->handleJson($app, 'GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1&debug=yes');
        self::assertSame('invalid_request', $invalidDebug['error']['code']);
    }

    public function testServeFrameDocumentExtractionPreservesFullDocumentsAndFallsBackWhenSrcdocIsMissing(): void
    {
        $app = $this->createApp([]);
        $container = $app->getContainer();
        self::assertNotNull($container);
        $action = $container->get(ServeFrameAction::class);
        self::assertInstanceOf(ServeFrameAction::class, $action);
        $extract = new ReflectionMethod(ServeFrameAction::class, 'frameDocument');

        $fullDocument = '<!doctype html><html><body><strong>Already complete</strong></body></html>';
        self::assertSame(
            $fullDocument,
            $extract->invoke($action, $this->decisionWithIframe(
                '<iframe srcdoc="' . htmlspecialchars($fullDocument, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></iframe>'
            ))
        );

        self::assertSame(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body></body></html>',
            $extract->invoke($action, $this->decisionWithIframe('<iframe title="Advertisement"></iframe>'))
        );
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

    public function testTurnstileOnlyProtectsRiskFlaggedTrackAndClickRequests(): void
    {
        $verificationTokens = [];
        $events = new InMemoryAdEventRepository();
        $app = $this->createApp(
            [$this->safeCandidate()],
            $events,
            new TurnstileVerifier(
                'secret-value',
                'https://turnstile.example/verify',
                static function (string $url, array $form) use (&$verificationTokens): array {
                    $verificationTokens[] = $form['response'];

                    return ['success' => true];
                },
            ),
        );

        $served = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]);

        $normalTrack = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-normal',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertTrue($normalTrack['data']['accepted']);

        $normalClick = $this->handleRaw($app, 'GET', '/api/v1/ads/click?decision_id=' . rawurlencode($served['data']['decision_id']) . '&viewer_id=viewer-1&event_id=clk-normal');
        self::assertSame(302, $normalClick->getStatusCode());
        self::assertSame([], $verificationTokens);

        $riskTrack = $this->handleJson($app, 'POST', '/api/v1/ads/track?risk=high', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-risk',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertSame('turnstile_token_required', $riskTrack['error']['code']);

        $riskClick = $this->handleJson($app, 'GET', '/api/v1/ads/click?decision_id=' . rawurlencode($served['data']['decision_id']) . '&viewer_id=viewer-1&event_id=clk-risk&abnormal=1');
        self::assertSame('turnstile_token_required', $riskClick['error']['code']);

        $acceptedRiskTrack = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'evt-risk-verified',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ], ['X-VertoAD-Risk' => 'high', 'CF-Turnstile-Token' => 'track-token']);
        self::assertTrue($acceptedRiskTrack['data']['accepted']);

        $riskClickServed = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-risk-click',
        ]);
        $riskClickImpression = $this->handleJson($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $riskClickServed['data']['decision_id'],
            'viewer_id' => 'viewer-risk-click',
            'event_id' => 'evt-risk-click-impression',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);
        self::assertTrue($riskClickImpression['data']['accepted']);

        $acceptedRiskClick = $this->handleRaw(
            $app,
            'GET',
            '/api/v1/ads/click?decision_id=' . rawurlencode($riskClickServed['data']['decision_id']) . '&viewer_id=viewer-risk-click&event_id=clk-risk-verified&high_risk=true',
            null,
            ['CF-Turnstile-Token' => 'click-token'],
        );
        self::assertSame(302, $acceptedRiskClick->getStatusCode());
        self::assertSame(['track-token', 'click-token'], $verificationTokens);
    }

    /**
     * @param list<AdCandidate> $candidates
     */
    private function createApp(
        array $candidates,
        ?InMemoryAdEventRepository $events = null,
        ?TurnstileVerifier $turnstileVerifier = null,
    ): App
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
        $app->get('/api/v1/ads/serve', ServeFrameAction::class);
        $app->post('/api/v1/ads/serve', ServeAction::class);
        $track = $app->post('/api/v1/ads/track', TrackAction::class);
        $click = $app->get('/api/v1/ads/click', ClickAction::class);
        if ($turnstileVerifier !== null) {
            $turnstile = new TurnstileMiddleware(
                $app->getResponseFactory(),
                $turnstileVerifier,
                null,
                null,
                new TurnstilePolicy(
                    enabled: true,
                    timeoutSeconds: 5,
                    protectedEndpoints: ['POST:/api/v1/auth/login'],
                    conditionalProtectedEndpoints: [
                        'POST:/api/v1/ads/track',
                        'GET:/api/v1/ads/click',
                    ],
                ),
                allowRuntimeBypass: false,
            );
            $track->add($turnstile);
            $click->add($turnstile);
        }
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(App $app, string $method, string $uri, ?array $payload = null, array $headers = []): array
    {
        $response = $this->handleRaw($app, $method, $uri, $payload, $headers);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $headers
     */
    private function handleRaw(App $app, string $method, string $uri, ?array $payload = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
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
            assetType: 'image',
            assetObjectKey: 'organizations/40/assets/creative.png',
            assetContentType: 'image/png',
        );
    }

    private function decisionWithIframe(string $iframeHtml): AdDecision
    {
        return new AdDecision(
            decisionId: 'decision-test',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            filled: true,
            reason: null,
            iframeHtml: $iframeHtml,
            width: 300,
            height: 250,
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            publisherOrganizationId: 42,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/landing',
            decidedAt: new DateTimeImmutable('2026-06-09T00:00:00+00:00'),
        );
    }
}
