<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Serving;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Service\Serving\AdServingService;
use VertoAD\Service\Serving\GeoResolverInterface;
use VertoAD\Service\Serving\NullGeoResolver;

final readonly class ServeAction
{
    public function __construct(
        private AdServingService $serving,
        private ?ClientIpResolver $ipResolver = null,
        private ?GeoResolverInterface $geoResolver = null,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $decision = $this->serving->serve(
                siteId: $this->positiveInt($body, 'site_id'),
                slotId: $this->positiveInt($body, 'slot_id'),
                viewerId: $this->viewerId($body),
                size: $this->size($body),
                debug: $this->boolField($body, 'debug', false),
                now: new DateTimeImmutable(),
                context: $this->requestContext($request),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, $this->decisionPayload($decision, $this->boolField($body, 'debug', false)), 200);
    }

    private function requestContext(ServerRequestInterface $request): ServingRequestContext
    {
        $context = $request->getAttribute(ServingRequestContext::class);
        if ($context instanceof ServingRequestContext) {
            return $this->withEndpoint($context);
        }

        $ip = ($this->ipResolver ?? new ClientIpResolver())->resolve($request);
        $userAgent = trim($request->getHeaderLine('User-Agent')) ?: null;

        return $this->withEndpoint(($this->geoResolver ?? new NullGeoResolver())->contextForRequest(
            $ip,
            $userAgent,
            RequestIdContext::fromRequest($request),
        ));
    }

    private function withEndpoint(ServingRequestContext $context): ServingRequestContext
    {
        return new ServingRequestContext(
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            geoCode: $context->geoCode,
            geoRecord: $context->geoRecord,
            requestId: $context->requestId,
            endpoint: '/api/v1/ads/serve',
            httpMethod: 'POST',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function positiveInt(array $body, string $field): int
    {
        if (!array_key_exists($field, $body) || !is_int($body[$field]) || $body[$field] <= 0) {
            throw new InvalidArgumentException($field . ' must be a positive integer.');
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function viewerId(array $body): string
    {
        if (!isset($body['viewer_id']) || !is_string($body['viewer_id']) || trim($body['viewer_id']) === '') {
            throw new InvalidArgumentException('viewer_id must be a non-empty string.');
        }

        return trim($body['viewer_id']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{width:int,height:int}|null
     */
    private function size(array $body): ?array
    {
        if (!array_key_exists('size', $body) || $body['size'] === null) {
            return null;
        }

        if (
            !is_array($body['size'])
            || !isset($body['size']['width'], $body['size']['height'])
            || !is_int($body['size']['width'])
            || !is_int($body['size']['height'])
            || $body['size']['width'] <= 0
            || $body['size']['height'] <= 0
        ) {
            throw new InvalidArgumentException('size must contain positive integer width and height.');
        }

        return ['width' => $body['size']['width'], 'height' => $body['size']['height']];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function boolField(array $body, string $field, bool $default): bool
    {
        if (!array_key_exists($field, $body)) {
            return $default;
        }

        if (!is_bool($body[$field])) {
            throw new InvalidArgumentException($field . ' must be a boolean.');
        }

        return $body[$field];
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionPayload(AdDecision $decision, bool $debug): array
    {
        $payload = [
            'decision_id' => $decision->decisionId,
            'filled' => $decision->filled,
            'reason' => $decision->reason,
            'iframe' => [
                'html' => $decision->iframeHtml,
                'width' => $decision->width,
                'height' => $decision->height,
            ],
            'ad' => $decision->filled ? [
                'id' => $decision->adId,
                'campaign_id' => $decision->campaignId,
                'click_url' => $this->clickUrl($decision),
            ] : null,
        ];

        if ($debug) {
            $payload['debug'] = [
                'eligibility_reason' => $decision->filled ? 'selected_first_safe_candidate' : $decision->reason,
            ];
        }

        return $payload;
    }

    private function clickUrl(AdDecision $decision): string
    {
        return '/api/v1/ads/click?decision_id=' . rawurlencode($decision->decisionId)
            . '&viewer_id=' . rawurlencode($decision->viewerId)
            . '&event_id={event_id}';
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
