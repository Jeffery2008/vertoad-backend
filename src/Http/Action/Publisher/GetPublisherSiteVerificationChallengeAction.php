<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Domain\Publisher\PublisherSiteVerificationMethod;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Service\PublisherSiteVerificationService;

final readonly class GetPublisherSiteVerificationChallengeAction
{
    public function __construct(
        private PublisherSiteRepositoryInterface $sites,
        private PublisherSiteVerificationService $verification,
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
            $method = PublisherSiteVerificationMethod::from((string) ($request->getQueryParams()['method'] ?? 'html_meta'));
            $challenge = $this->verification->expectedChallenge($site->id, $method);
        } catch (\ValueError) {
            return PublisherJson::write($response, ['code' => 'invalid_request', 'message' => 'Unsupported verification method.'], 422);
        } catch (RuntimeException $exception) {
            return $this->runtimeError($response, $exception);
        }

        return PublisherJson::write($response, PublisherJson::challenge($challenge), 200);
    }

    private function runtimeError(ResponseInterface $response, RuntimeException $exception): ResponseInterface
    {
        $code = $exception->getMessage();
        $status = $code === 'publisher_site_not_found' ? 404 : 422;

        return PublisherJson::write($response, ['code' => $code, 'message' => $code], $status);
    }
}
