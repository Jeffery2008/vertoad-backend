<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Serving;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Serving\AdServingService;

final readonly class ServeFrameAction
{
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

        $response = $response->withStatus(200)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($decision->iframeHtml);

        return $response;
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
