<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final class PublisherRequestGuards
{
    /** @return array{payload: array<string, string>, status: int}|null */
    public static function requireAuthenticatedOrganization(RequestUserContext $context, ServerRequestInterface $request): ?array
    {
        if ($context->user === null) {
            return [
                'payload' => ['code' => 'authentication_required', 'message' => 'Authentication is required for this endpoint.'],
                'status' => 401,
            ];
        }

        if (self::positiveInteger($request->getQueryParams()['organization_id'] ?? null) === null || $context->organizationId === null) {
            return [
                'payload' => ['code' => 'organization_scope_required', 'message' => 'A positive organization_id query parameter is required.'],
                'status' => 400,
            ];
        }

        return null;
    }

    public static function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    public static function requireOwnedSite(PublisherSiteRepositoryInterface $sites, mixed $siteId, int $organizationId): PublisherSite
    {
        $id = self::positiveInteger($siteId);
        if ($id === null) {
            throw new RuntimeException('invalid_request');
        }

        $site = $sites->findById($id);
        if ($site === null || $site->organizationId !== $organizationId) {
            throw new RuntimeException('publisher_site_not_found');
        }

        return $site;
    }
}
