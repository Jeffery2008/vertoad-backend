<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Publisher\PublisherIntegrationCodeService;

final readonly class GetPublisherAdSlotIntegrationCodeAction
{
    public function __construct(private PublisherIntegrationCodeService $integration)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = PublisherRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return PublisherJson::write($response, $error['payload'], $error['status']);
        }

        $siteId = PublisherRequestGuards::positiveInteger($args['site_id'] ?? null);
        $slotId = PublisherRequestGuards::positiveInteger($args['slot_id'] ?? null);
        if ($siteId === null || $slotId === null) {
            return PublisherJson::write($response, ['code' => 'invalid_request', 'message' => 'site_id and slot_id must be positive integers.'], 404);
        }

        $code = $this->integration->forSlot((int) $context->organizationId, $siteId, $slotId);
        if ($code === null) {
            return PublisherJson::write($response, ['code' => 'publisher_ad_slot_not_found', 'message' => 'publisher_ad_slot_not_found'], 404);
        }

        return PublisherJson::write($response, $code, 200);
    }
}
