<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Campaigns;

use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;

final class CampaignRequestGuards
{
    /** @return array{payload: array<string, string>, status: int}|null */
    public static function requireAuthenticatedOrganization(RequestUserContext $context, ServerRequestInterface $request): ?array
    {
        if ($context->user === null) {
            return [
                'payload' => [
                    'code' => 'authentication_required',
                    'message' => 'Authentication is required for this endpoint.',
                ],
                'status' => 401,
            ];
        }

        $organizationId = $request->getQueryParams()['organization_id'] ?? null;
        if (self::positiveInteger($organizationId) === null || $context->organizationId === null) {
            return [
                'payload' => [
                    'code' => 'organization_scope_required',
                    'message' => 'A positive organization_id query parameter is required.',
                ],
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
}
