<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\AdSlotSetupService;

final readonly class ListPublisherAdSlotPresetsAction
{
    public function __construct(private AdSlotSetupService $slots)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = PublisherRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return PublisherJson::write($response, $error['payload'], $error['status']);
        }

        return PublisherJson::write($response, PublisherJson::presets($this->slots->presetSizes()), 200);
    }
}
