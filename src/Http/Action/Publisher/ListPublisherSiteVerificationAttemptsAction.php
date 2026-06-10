<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepositoryInterface;

final readonly class ListPublisherSiteVerificationAttemptsAction
{
    public function __construct(
        private PublisherSiteRepositoryInterface $sites,
        private PublisherSiteVerificationAttemptRepositoryInterface $attempts,
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
            $code = $exception->getMessage();
            $status = $code === 'publisher_site_not_found' ? 404 : 422;

            return PublisherJson::write($response, ['code' => $code, 'message' => $code], $status);
        }

        return PublisherJson::write(
            $response,
            PublisherJson::attempts($this->attempts->listForSite($site->id, $site->organizationId)),
            200,
        );
    }
}
