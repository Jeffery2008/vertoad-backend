<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Serving;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Serving\AdServingService;

final readonly class ClickAction
{
    public function __construct(private AdServingService $serving)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $query = $request->getQueryParams();
            $result = $this->serving->recordClick(
                decisionId: $this->stringField($query, 'decision_id'),
                viewerId: $this->stringField($query, 'viewer_id'),
                eventId: $this->stringField($query, 'event_id'),
                occurredAt: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        if (!$result->accepted || $result->redirectUrl === null) {
            return $this->json($response, ['code' => $result->reason, 'message' => $result->reason], 422);
        }

        return $response->withStatus(302)->withHeader('Location', $result->redirectUrl);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function stringField(array $query, string $field): string
    {
        if (!isset($query[$field]) || !is_string($query[$field]) || trim($query[$field]) === '') {
            throw new InvalidArgumentException($field . ' must be a non-empty string.');
        }

        return trim($query[$field]);
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
