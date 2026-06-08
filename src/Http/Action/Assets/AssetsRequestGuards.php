<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Assets;

use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;

final class AssetsRequestGuards
{
    /**
     * @return array{payload: array<string, string>, status: int}|null
     */
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
        if (!self::isPositiveInteger($organizationId) || $context->organizationId === null) {
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

    private static function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0;
    }
}
