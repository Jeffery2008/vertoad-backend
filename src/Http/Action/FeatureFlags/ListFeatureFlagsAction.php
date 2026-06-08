<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\FeatureFlags;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Operations\OperationsJson;
use VertoAD\Service\FeatureFlags\FeatureFlagService;

final readonly class ListFeatureFlagsAction
{
    public function __construct(private FeatureFlagService $flags)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return OperationsJson::write($response, ['feature_flags' => $this->flags->listFlags()]);
    }
}
