<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final readonly class CreatePublisherSiteAction
{
    public function __construct(private PublisherSiteRepositoryInterface $sites)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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

        $name = trim((string) ($body['name'] ?? ''));
        $domain = $this->normalizeDomain((string) ($body['domain'] ?? ''));
        if ($name === '' || $domain === '') {
            return PublisherJson::write($response, ['code' => 'invalid_request', 'message' => 'Publisher site name and domain are required.'], 422);
        }

        $site = $this->sites->create((int) $context->organizationId, $name, $domain, bin2hex(random_bytes(24)));

        return PublisherJson::write($response, PublisherJson::site($site), 201);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];

        return trim($domain, ". \t\n\r\0\x0B");
    }
}
