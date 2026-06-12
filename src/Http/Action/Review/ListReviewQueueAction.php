<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Review;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Review\ReviewValidationException;
use VertoAD\Service\ReviewService;

final readonly class ListReviewQueueAction
{
    private const int DEFAULT_LIMIT = 50;

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

        $query = $request->getQueryParams();
        $status = $query['status'] ?? null;
        if ($status !== 'needs_human') {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'status must be needs_human.'], 422);
        }

        $limit = $this->limit($query['limit'] ?? null);
        if ($limit === null) {
            return ReviewSerializers::json($response, ['code' => 'invalid_request', 'message' => 'limit must be a positive integer no greater than 100.'], 422);
        }

        try {
            $reviews = $this->service->listQueue((int) $context->organizationId, $status, $limit);
        } catch (ReviewValidationException $exception) {
            return ReviewSerializers::json($response, ['code' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->status);
        }

        return ReviewSerializers::json($response, [
            'reviews' => array_map(ReviewSerializers::review(...), $reviews),
        ], 200);
    }

    private function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        if (is_string($value) && !ctype_digit($value)) {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 && $limit <= 100 ? $limit : null;
    }
}
