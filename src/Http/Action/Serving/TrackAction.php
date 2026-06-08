<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Serving;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Serving\AdServingService;

final readonly class TrackAction
{
    public function __construct(private AdServingService $serving)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = $this->body($request);
            $result = $this->serving->trackImpression(
                decisionId: $this->stringField($body, 'decision_id'),
                viewerId: $this->stringField($body, 'viewer_id'),
                visibleRatio: $this->floatField($body, 'visible_ratio'),
                visibleMs: $this->intField($body, 'visible_ms'),
                eventId: $this->stringField($body, 'event_id'),
                occurredAt: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        if (!$result->accepted) {
            return $this->json($response, ['code' => $result->reason, 'message' => $result->reason], 422);
        }

        return $this->json($response, ['accepted' => true, 'duplicate' => $result->duplicate], 200);
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
    private function stringField(array $body, string $field): string
    {
        if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '') {
            throw new InvalidArgumentException($field . ' must be a non-empty string.');
        }

        return trim($body[$field]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $field): int
    {
        if (!isset($body[$field]) || !is_int($body[$field]) || $body[$field] < 0) {
            throw new InvalidArgumentException($field . ' must be a non-negative integer.');
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function floatField(array $body, string $field): float
    {
        if (!isset($body[$field]) || (!is_float($body[$field]) && !is_int($body[$field]))) {
            throw new InvalidArgumentException($field . ' must be a number.');
        }

        $value = (float) $body[$field];
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException($field . ' must be between 0 and 1.');
        }

        return $value;
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
