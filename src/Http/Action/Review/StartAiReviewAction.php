<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Review;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Review\ReviewValidationException;
use VertoAD\Service\ReviewService;

final readonly class StartAiReviewAction
{
    public function __construct(private ReviewService $service)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = ReviewRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return ReviewSerializers::json($response, $error['payload'], $error['status']);
        }

        $assetId = ReviewRequestGuards::positiveRouteInteger($args['asset_id'] ?? null);
        if ($assetId === null) {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'asset_id must be a positive integer.'], 422);
        }

        $body = $request->getParsedBody();
        if ($body !== null && !is_array($body)) {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'JSON object body is required when present.'], 422);
        }

        try {
            $review = $this->service->requestAiReview(
                (int) $context->organizationId,
                (int) $context->user?->id,
                $assetId,
                $this->optionalString($body ?? [], 'landing_url'),
                $this->optionalString($body ?? [], 'copy'),
            );
        } catch (ReviewValidationException $exception) {
            return ReviewSerializers::json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return ReviewSerializers::json($response, ReviewSerializers::review($review), 201);
    }

    /** @param array<string, mixed> $body */
    private function optionalString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        if (!is_string($body[$key])) {
            throw new ReviewValidationException('invalid_request', $key . ' must be a string when present.');
        }

        $trimmed = trim($body[$key]);

        return $trimmed === '' ? null : $trimmed;
    }
}
