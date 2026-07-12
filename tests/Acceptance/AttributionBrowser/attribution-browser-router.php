<?php

declare(strict_types=1);

use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use VertoAD\AppFactory;

$rootPath = dirname(__DIR__, 3);
require $rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('The attribution HTTP bridge request must be a JSON object.');
    }

    $method = strtoupper(trim((string) ($input['method'] ?? '')));
    $uri = trim((string) ($input['uri'] ?? ''));
    $headers = $input['headers'] ?? [];
    $body = base64_decode((string) ($input['body_base64'] ?? ''), true);
    if ($method === '' || $uri === '' || !is_array($headers) || $body === false) {
        throw new RuntimeException('The attribution HTTP bridge request is incomplete.');
    }

    $request = (new ServerRequestFactory())->createServerRequest(
        $method,
        $uri,
        ['REMOTE_ADDR' => '127.0.0.1'],
    );
    foreach ($headers as $name => $value) {
        if (is_string($name) && is_string($value) && trim($name) !== '') {
            $request = $request->withHeader($name, $value);
        }
    }
    if ($body !== '') {
        $request = $request->withBody((new StreamFactory())->createStream($body));
    }

    $path = $request->getUri()->getPath();
    $response = match ($path) {
        '/publisher' => attributionFixtureResponse(200, publisherFixtureHtml()),
        '/checkout' => attributionFixtureResponse(200, checkoutFixtureHtml()),
        '/favicon.ico' => (new ResponseFactory())->createResponse(204),
        default => AppFactory::create($rootPath)->handle($request),
    };

    echo json_encode([
        'status' => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body_base64' => base64_encode((string) $response->getBody()),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function attributionFixtureResponse(int $status, string $body): Psr\Http\Message\ResponseInterface
{
    $response = (new ResponseFactory())->createResponse($status)
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store');
    $response->getBody()->write($body);

    return $response;
}

function publisherFixtureHtml(): string
{
    $clickUrl = '/api/v1/ads/click?' . http_build_query([
        'decision_id' => attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_DECISION_ID'),
        'viewer_id' => attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_VIEWER_ID'),
        'event_id' => attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_CLICK_EVENT_ID'),
    ], '', '&', PHP_QUERY_RFC3986);

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Publisher attribution acceptance</title>'
        . '<style>'
        . 'body{margin:0;background:#f7f8fa;color:#17191d;font:15px/1.5 Arial,sans-serif}'
        . 'main{width:min(720px,calc(100% - 32px));margin:48px auto}'
        . '.ad{border:1px solid #d9dde5;background:#fff;padding:24px;box-shadow:0 8px 24px rgba(24,28,36,.08)}'
        . '.eyebrow{color:#596273;font-size:12px;text-transform:uppercase}'
        . 'h1{font-size:28px;margin:8px 0 12px}p{color:#596273;margin:0 0 20px}'
        . 'a{display:inline-block;background:#2563eb;color:#fff;padding:10px 16px;text-decoration:none;border-radius:6px}'
        . '</style></head><body><main data-attribution-page="publisher"'
        . ' data-viewer-id="' . attributionFixtureEscape(attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_VIEWER_ID')) . '"'
        . ' data-decision-id="' . attributionFixtureEscape(attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_DECISION_ID')) . '">'
        . '<section class="ad"><div class="eyebrow">Sponsored</div><h1>Analytics workspace</h1>'
        . '<p>Open the advertiser checkout through the tracked redirect.</p>'
        . '<a id="tracked-ad-link" href="' . attributionFixtureEscape($clickUrl) . '">View offer</a>'
        . '</section></main></body></html>';
}

function checkoutFixtureHtml(): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Advertiser conversion acceptance</title>'
        . '<style>'
        . 'body{margin:0;background:#f7f8fa;color:#17191d;font:15px/1.5 Arial,sans-serif}'
        . 'main{width:min(760px,calc(100% - 32px));margin:40px auto}'
        . '.panel{border:1px solid #d9dde5;background:#fff;padding:24px;box-shadow:0 8px 24px rgba(24,28,36,.08)}'
        . 'h1{font-size:28px;margin:0 0 10px}p{color:#596273;margin:0 0 20px}'
        . 'button{border:0;border-radius:6px;background:#15803d;color:#fff;padding:10px 16px;font:inherit;cursor:pointer}'
        . 'button:disabled{cursor:wait;opacity:.7}.status{margin-top:18px;color:#374151}'
        . '.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;margin-top:24px;background:#d9dde5;border:1px solid #d9dde5}'
        . '.metric{background:#fff;padding:14px}.metric span{display:block;color:#596273;font-size:12px}.metric strong{font-size:22px}'
        . '@media(max-width:560px){.metrics{grid-template-columns:1fr}.metric{display:flex;justify-content:space-between;align-items:center}}'
        . '</style></head><body><main data-attribution-page="checkout"'
        . ' data-viewer-id="' . attributionFixtureEscape(attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_VIEWER_ID')) . '"'
        . ' data-conversion-event-id="' . attributionFixtureEscape(attributionFixtureEnvironment('VERTOAD_ATTRIBUTION_BROWSER_CONVERSION_EVENT_ID')) . '">'
        . '<section class="panel"><h1>Complete purchase</h1>'
        . '<p>The browser conversion pixel is sent only after this explicit action.</p>'
        . '<button id="complete-purchase" type="button">Complete purchase</button>'
        . '<div id="pixel-status" class="status" role="status">Awaiting conversion</div>'
        . '<div class="metrics" aria-label="ROI report evidence">'
        . '<div class="metric"><span>Attributed conversions</span><strong id="report-conversions">-</strong></div>'
        . '<div class="metric"><span>Spend points</span><strong id="report-spend">-</strong></div>'
        . '<div class="metric"><span>ROI</span><strong id="report-roi">-</strong></div>'
        . '</div></section></main>'
        . '<script>'
        . 'const button=document.querySelector("#complete-purchase");'
        . 'button.addEventListener("click",async()=>{'
        . 'button.disabled=true;document.querySelector("#pixel-status").textContent="Recording conversion";'
        . 'const page=document.querySelector("[data-attribution-page=checkout]");'
        . 'const query=new URLSearchParams({event_id:page.dataset.conversionEventId,viewer_id:page.dataset.viewerId,'
        . 'conversion_name:"purchase",value_points:"200"});'
        . 'const url="/api/v1/attribution/pixel?"+query.toString();'
        . 'try{const response=await fetch(url,{cache:"no-store",credentials:"omit"});'
        . 'const body=await response.json();window.__vertoAttributionPixel={status:response.status,'
        . 'requestId:response.headers.get("x-request-id"),url,body};'
        . 'document.querySelector("#pixel-status").textContent=response.ok?"Conversion attributed":"Conversion failed";'
        . '}catch(error){window.__vertoAttributionPixel={status:0,url,error:String(error)};'
        . 'document.querySelector("#pixel-status").textContent="Conversion failed";throw error;}'
        . 'finally{button.disabled=false;}});'
        . '</script></body></html>';
}

function attributionFixtureEnvironment(string $name): string
{
    $value = getenv($name);
    $value = $value === false ? '' : trim((string) $value);
    if ($value === '') {
        throw new RuntimeException($name . ' is required by the attribution browser fixture.');
    }

    return $value;
}

function attributionFixtureEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
