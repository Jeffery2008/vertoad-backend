<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Http\Action\Serving\ServeFrameAction;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Repository\Serving\StaticServingInventoryRepository;
use VertoAD\Service\Assets\AssetPublicUrlResolver;
use VertoAD\Service\Serving\AdServingService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$origin = trim((string) ($argv[1] ?? ''));
if (preg_match('#^http://127\.0\.0\.1:\d+$#D', $origin) !== 1) {
    fwrite(STDERR, "A loopback HTTP origin is required.\n");
    exit(2);
}

$candidate = new AdCandidate(
    adId: 'video-browser-smoke',
    campaignId: 3001,
    advertiserOrganizationId: 4001,
    creativeHtml: '',
    landingUrl: 'https://advertiser.example/landing',
    width: 300,
    height: 250,
    impressionCostPoints: 10,
    clickCostPoints: 20,
    assetType: 'video',
    assetObjectKey: 'organizations/4001/assets/video-browser-smoke.mp4',
    assetContentType: 'video/mp4',
    assetUrl: $origin . '/video.mp4',
    snapshotWebpUrl: $origin . '/poster.webp',
);
$serving = new AdServingService(
    new StaticServingInventoryRepository([[1001, 2001]]),
    new StaticAdCandidateRepository([$candidate]),
    new InMemoryAdDecisionRepository(),
    new InMemoryAdEventRepository(),
);
$action = new ServeFrameAction(
    $serving,
    publicAssets: new AssetPublicUrlResolver($origin, allowHttp: true),
);
$request = (new ServerRequestFactory())
    ->createServerRequest('GET', $origin . '/api/v1/ads/serve')
    ->withQueryParams([
        'site_id' => '1001',
        'slot_id' => '2001',
        'viewer_id' => 'viewer-video-browser-smoke',
        'width' => '300',
        'height' => '250',
    ]);
$response = $action($request, new Response());
if ($response->getStatusCode() !== 200) {
    fwrite(STDERR, 'Serve frame fixture returned HTTP ' . $response->getStatusCode() . ".\n");
    exit(1);
}

echo json_encode([
    'body' => (string) $response->getBody(),
    'content_security_policy' => $response->getHeaderLine('Content-Security-Policy'),
    'content_type' => $response->getHeaderLine('Content-Type'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
