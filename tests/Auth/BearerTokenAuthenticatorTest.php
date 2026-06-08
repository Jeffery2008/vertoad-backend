<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;

final class BearerTokenAuthenticatorTest extends TestCase
{
    public function testAuthenticatesBearerTokenAndCarriesOrganizationScope(): void
    {
        $authenticator = new BearerTokenAuthenticator(new FixedSessionRepository());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/me?organization_id=42')
            ->withHeader('Authorization', 'Bearer plain-session-token');

        $authenticated = $authenticator->authenticate($request, new DateTimeImmutable('2026-06-07 12:00:00'));
        $context = RequestUserContext::fromRequest($authenticated);

        self::assertNotNull($context->user);
        self::assertSame(9, $context->user->id);
        self::assertSame(42, $context->organizationId);
    }

    public function testLeavesRequestAnonymousForMissingOrUnknownToken(): void
    {
        $authenticator = new BearerTokenAuthenticator(new FixedSessionRepository());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/auth/me');

        self::assertNull(RequestUserContext::fromRequest($authenticator->authenticate($request))->user);

        $unknown = $request->withHeader('Authorization', 'Bearer unknown');
        self::assertNull(RequestUserContext::fromRequest($authenticator->authenticate($unknown))->user);
    }

    public function testIgnoresNonPositiveOrganizationScope(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/me?organization_id=0')
            ->withHeader('Authorization', 'Bearer plain-session-token');

        $context = RequestUserContext::fromRequest((new BearerTokenAuthenticator(new FixedSessionRepository()))->authenticate($request));

        self::assertNotNull($context->user);
        self::assertNull($context->organizationId);
    }

    public function testAcceptsIntegerOrganizationScopeAttribute(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/me')
            ->withQueryParams(['organization_id' => 42])
            ->withHeader('Authorization', 'Bearer plain-session-token');

        $context = RequestUserContext::fromRequest((new BearerTokenAuthenticator(new FixedSessionRepository()))->authenticate($request));

        self::assertSame(42, $context->organizationId);
    }
}

final class FixedSessionRepository implements FirstPartySessionRepositoryInterface
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
    }

    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        if ($tokenHash !== hash('sha256', 'plain-session-token')) {
            return null;
        }

        return new AuthenticatedUser(9, 'owner@example.com', false);
    }

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
    {
        return true;
    }
}
