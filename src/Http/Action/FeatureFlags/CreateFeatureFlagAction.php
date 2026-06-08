<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\FeatureFlags;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\FeatureFlags\FeatureFlagService;

final readonly class CreateFeatureFlagAction
{
    public function __construct(private FeatureFlagService $flags)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $context = RequestUserContext::fromRequest($request);

        try {
            $flag = $this->flags->createOrUpdate($payload, $context->user?->id ?? 0);
            if (($payload['publish'] ?? false) === true) {
                $flag = $this->flags->publish($flag, $context->user?->id ?? 0);
            }

            return OperationsJson::write($response, $flag, 201);
        } catch (\InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }
    }
}
