<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;

final readonly class CreatePublisherAdSlotAction
{
    public function __construct(
        private PublisherSiteRepositoryInterface $sites,
        private AdSlotSetupService $slots,
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

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return PublisherJson::write($response, ['code' => 'invalid_request', 'message' => 'JSON object body is required.'], 422);
        }

        try {
            $site = PublisherRequestGuards::requireOwnedSite($this->sites, $args['site_id'] ?? null, (int) $context->organizationId);
            $slot = isset($body['size_preset'])
                ? $this->slots->createPresetSlot(
                    $site->id,
                    (string) ($body['name'] ?? ''),
                    (string) ($body['slot_key'] ?? ''),
                    (string) $body['size_preset'],
                    (bool) ($body['responsive'] ?? false),
                    is_array($body['responsive_rules'] ?? null) ? $body['responsive_rules'] : null,
                )
                : $this->slots->createCustomSlot(
                    $site->id,
                    (string) ($body['name'] ?? ''),
                    (string) ($body['slot_key'] ?? ''),
                    new AdSlotSize((int) ($body['width'] ?? 0), (int) ($body['height'] ?? 0)),
                    (bool) ($body['responsive'] ?? false),
                    is_array($body['responsive_rules'] ?? null) ? $body['responsive_rules'] : null,
                );
        } catch (InvalidArgumentException $exception) {
            return PublisherJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->runtimeError($response, $exception);
        }

        return PublisherJson::write($response, PublisherJson::slot($slot), 201);
    }

    private function runtimeError(ResponseInterface $response, RuntimeException $exception): ResponseInterface
    {
        $code = $exception->getMessage() === 'publisher_site_not_found' ? 'publisher_site_not_found' : 'publisher_site_not_verified';
        $status = $code === 'publisher_site_not_found' ? 404 : 422;

        return PublisherJson::write($response, ['code' => $code, 'message' => $exception->getMessage()], $status);
    }
}
