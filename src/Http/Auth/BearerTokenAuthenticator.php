<?php

declare(strict_types=1);

namespace VertoAD\Http\Auth;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;

final readonly class BearerTokenAuthenticator
{
    public function __construct(private FirstPartySessionRepositoryInterface $sessions)
    {
    }

    public function authenticate(ServerRequestInterface $request, ?DateTimeImmutable $now = null): ServerRequestInterface
    {
        $token = $this->bearerToken($request);
        if ($token === null) {
            return $request->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext());
        }

        $user = $this->sessions->findActiveUserByTokenHash(hash('sha256', $token), $now ?? new DateTimeImmutable());
        if ($user === null) {
            return $request->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext());
        }

        return $request->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
            user: $user,
            organizationId: $this->organizationId($request),
        ));
    }

    public function tokenHashFromRequest(ServerRequestInterface $request): ?string
    {
        $token = $this->bearerToken($request);

        return $token === null ? null : hash('sha256', $token);
    }

    private function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    private function organizationId(ServerRequestInterface $request): ?int
    {
        $value = $request->getQueryParams()['organization_id'] ?? null;
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
