<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\FeatureFlags;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Service\FeatureFlags\FeatureFlagService;

final readonly class EvaluateFeatureFlagAction
{
    public function __construct(private FeatureFlagService $flags)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $payload = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return OperationsJson::write($response, $this->flags->evaluate((string) ($args['flag_key'] ?? ''), $payload));
    }
}
