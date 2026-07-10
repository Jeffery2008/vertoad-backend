<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final readonly class ListPublisherAdSlotsAction
{
    public function __construct(
        private PublisherSiteRepositoryInterface $sites,
        private AdSlotRepositoryInterface $slots,
    ) {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = PublisherRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return PublisherJson::write($response, $error['payload'], $error['status']);
        }

        try {
            $site = PublisherRequestGuards::requireOwnedSite($this->sites, $args['site_id'] ?? null, (int) $context->organizationId);
        } catch (RuntimeException $exception) {
            return PublisherJson::write($response, ['code' => $exception->getMessage(), 'message' => $exception->getMessage()], 404);
        }

        return PublisherJson::write($response, PublisherJson::slots($this->slots->listForSite($site->id), $site->organizationId), 200);
    }
}
