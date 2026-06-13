<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Serving;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Service\Serving\AdServingService;

final readonly class ServeFrameAction
{
    private const TRACK_PATH = '/api/v1/ads/track';
    private const CLICK_PATH = '/api/v1/ads/click';

    public function __construct(private AdServingService $serving)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $query = $request->getQueryParams();
            $decision = $this->serving->serve(
                siteId: $this->positiveInt($query, 'site_id'),
                slotId: $this->positiveInt($query, 'slot_id'),
                viewerId: $this->viewerId($query),
                size: $this->size($query),
                debug: $this->boolField($query, 'debug', false),
                now: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        $nonce = $this->nonce();
        $response = $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader(
                'Content-Security-Policy',
                "sandbox allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts; default-src 'none'; img-src 'self' https: data:; connect-src 'self'; style-src 'unsafe-inline'; script-src 'nonce-" . $nonce . "'; base-uri 'none'; form-action 'none'",
            );
        $response->getBody()->write($this->frameDocument($decision, $nonce));

        return $response;
    }

    private function frameDocument(AdDecision $decision, ?string $nonce = null): string
    {
        if (preg_match('/\ssrcdoc="([^"]*)"/', $decision->iframeHtml, $matches) === 1) {
            $html = html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $document = str_starts_with(strtolower($html), '<!doctype html>') ? $html : $this->wrapFragment($html);

            return $nonce !== null && $decision->filled ? $this->injectRuntimeBridge($document, $decision, $nonce) : $document;
        }

        return $this->wrapFragment('');
    }

    private function injectRuntimeBridge(string $html, AdDecision $decision, string $nonce): string
    {
        $script = $this->runtimeBridgeScript($decision, $nonce);
        $html = (string) preg_replace('/<script(?![^>]*\snonce=)/i', '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"', $html);
        $withRuntime = preg_replace('/<\/body\s*>/i', $script . '</body>', $html, 1);

        return is_string($withRuntime) ? $withRuntime : $html . $script;
    }

    private function wrapFragment(string $fragment): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>' . $fragment . '</body></html>';
    }

    private function runtimeBridgeScript(AdDecision $decision, string $nonce): string
    {
        $config = [
            'protocol' => 'vertoad',
            'decisionId' => $decision->decisionId,
            'siteId' => $decision->siteId,
            'slotId' => $decision->slotId,
            'viewerId' => $decision->viewerId,
            'trackUrl' => self::TRACK_PATH,
            'clickBaseUrl' => $this->clickBaseUrl($decision),
            'visibleRatio' => 0.5,
            'visibleMs' => 1000,
        ];
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $escapedNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<script nonce="{$escapedNonce}" data-vertoad-runtime>
(() => {
  "use strict";
  const config = {$json};
  const protocolMessage = { protocol: "vertoad" };
  let impressionTracked = false;
  const post = (type, detail) => {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ ...protocolMessage, type, detail }, "*");
    }
  };
  const eventId = (prefix) => prefix + ":" + config.decisionId + ":" + Date.now().toString(36) + ":" + Math.random().toString(36).slice(2);
  const envelopeData = (payload) => payload && typeof payload === "object" && "data" in payload ? payload.data : payload;
  const trackImpression = async () => {
    if (impressionTracked) {
      return;
    }
    impressionTracked = true;
    const impressionEventId = eventId("imp");
    try {
      const response = await fetch(config.trackUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Accept": "application/json",
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          decision_id: config.decisionId,
          viewer_id: config.viewerId,
          event_id: impressionEventId,
          visible_ratio: config.visibleRatio,
          visible_ms: config.visibleMs
        })
      });
      const payload = await response.json().catch(() => ({}));
      const data = envelopeData(payload);
      if (!response.ok || !data || data.accepted !== true) {
        throw new Error("impression tracking rejected");
      }
      post("impression_tracked", { event_id: impressionEventId, duplicate: data.duplicate === true });
    } catch (error) {
      impressionTracked = false;
      post("error", { message: "impression_tracking_failed" });
    }
  };
  window.addEventListener("message", (event) => {
    const data = event.data;
    if (
      event.source !== window.parent ||
      !data ||
      data.protocol !== config.protocol ||
      data.type !== "impression_eligible" ||
      data.viewerId !== config.viewerId ||
      Number(data.siteId) !== config.siteId ||
      Number(data.slotId) !== config.slotId
    ) {
      return;
    }
    void trackImpression();
  });
  document.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target.closest("[data-vertoad-click-target]") : null;
    if (!(target instanceof HTMLAnchorElement)) {
      return;
    }
    const clickUrl = new URL(config.clickBaseUrl, window.location.href);
    clickUrl.searchParams.set("event_id", eventId("clk"));
    target.href = clickUrl.toString();
    post("click_requested", { href: target.href });
  }, { capture: true });
  post("ready", { decision_id: config.decisionId });
})();
</script>
HTML;
    }

    private function clickBaseUrl(AdDecision $decision): string
    {
        return self::CLICK_PATH . '?' . http_build_query([
            'decision_id' => $decision->decisionId,
            'viewer_id' => $decision->viewerId,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function positiveInt(array $query, string $field): int
    {
        $value = $query[$field] ?? null;
        if (!is_string($value) || !ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function viewerId(array $query): string
    {
        $value = $query['viewer_id'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('viewer_id must be a non-empty string.');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{width:int,height:int}|null
     */
    private function size(array $query): ?array
    {
        if (!array_key_exists('width', $query) && !array_key_exists('height', $query)) {
            return null;
        }

        if (!array_key_exists('width', $query) || !array_key_exists('height', $query)) {
            throw new InvalidArgumentException('size must contain positive integer width and height.');
        }

        return [
            'width' => $this->positiveInt($query, 'width'),
            'height' => $this->positiveInt($query, 'height'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function boolField(array $query, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $query)) {
            return $default;
        }

        return match ($query[$field]) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new InvalidArgumentException($field . ' must be a boolean.'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
