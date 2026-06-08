<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Review;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Review\ReviewValidationException;
use VertoAD\Service\ReviewService;

final readonly class GetReviewStatusAction
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

        try {
            $review = $this->service->getStatus((int) $context->organizationId, $reviewId);
        } catch (ReviewValidationException $exception) {
            return ReviewSerializers::json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return ReviewSerializers::json($response, ReviewSerializers::review($review), 200);
    }
}
