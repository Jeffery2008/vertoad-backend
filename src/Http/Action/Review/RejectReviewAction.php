<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Review;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Review\ReviewValidationException;
use VertoAD\Service\ReviewService;

final readonly class RejectReviewAction
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

        $reviewId = ReviewRequestGuards::positiveRouteInteger($args['review_id'] ?? null);
        if ($reviewId === null) {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'review_id must be a positive integer.'], 422);
        }

        $body = $request->getParsedBody();
        if ($body !== null && !is_array($body)) {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'JSON object body is required when present.'], 422);
        }

        $reason = $body['reason'] ?? null;
        if ($reason !== null && !is_string($reason)) {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'reason must be a string when present.'], 422);
        }

        try {
            $review = $this->service->reject((int) $context->organizationId, (int) $context->user?->id, $reviewId, $reason);
        } catch (ReviewValidationException $exception) {
            return ReviewSerializers::json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return ReviewSerializers::json($response, ReviewSerializers::review($review), 200);
    }
}
