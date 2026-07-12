<?php

declare(strict_types=1);

namespace VertoAD\Tests\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepositoryInterface;

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

    public function testAuthenticatesClientCredentialsAccessTokenAndCarriesOAuthScopeContext(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/attribution/conversions?organization_id=123')
            ->withHeader('Authorization', 'Bearer client-credentials-token');

        $context = RequestUserContext::fromRequest(
            (new BearerTokenAuthenticator(new FixedSessionRepository(), new FixedOAuthTokenRepository()))
                ->authenticate($request, new DateTimeImmutable('2026-06-07 12:00:00')),
        );

        self::assertTrue($context->isAuthenticated());
        self::assertNull($context->user);
        self::assertSame(99, $context->organizationId);
        self::assertNotNull($context->oauthToken);
        self::assertSame(501, $context->oauthToken->clientId);
        self::assertSame('vocl_conversion_client', $context->oauthToken->clientIdentifier);
        self::assertTrue($context->hasOAuthScope('attribution.conversion.write.own'));
        self::assertFalse($context->hasOAuthScope('campaign.write.own'));
    }

    public function testRequestUserContextOAuthScopeMatchingHandlesAnonymousAndWildcards(): void
    {
        self::assertFalse((new RequestUserContext())->hasOAuthScope('attribution.conversion.write.own'));

        $context = new RequestUserContext(
            oauthToken: new OAuthAccessTokenContext(
                accessTokenId: 602,
                clientId: 502,
                clientIdentifier: 'vocl_wildcard_client',
                organizationId: 99,
                user: null,
                scopes: ['attribution.*'],
            ),
        );

        self::assertTrue($context->hasOAuthScope('attribution.conversion.write.own'));
        self::assertFalse($context->hasOAuthScope('campaign.write.own'));
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

final class FixedOAuthTokenRepository implements OAuthTokenRepositoryInterface
{
    public function createAuthorizationCode(OAuthClient $client, int $userId, ?int $organizationId, string $codeHash, string $redirectUri, array $scopes, string $codeChallenge, string $codeChallengeMethod, DateTimeImmutable $expiresAt): int
    {
        throw new \LogicException('Not used by this test.');
    }

    public function consumeAuthorizationCode(
        string $codeHash,
        int $clientId,
        string $redirectUri,
        string $codeChallenge,
        DateTimeImmutable $now,
    ): ?array
    {
        throw new \LogicException('Not used by this test.');
    }

    public function revokeAuthorizationCode(string $codeHash, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function isAuthorizationCodeActive(string $codeHash, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function createAccessToken(OAuthClient $client, ?int $userId, ?int $organizationId, ?int $authorizationCodeId, string $accessTokenHash, array $scopes, DateTimeImmutable $expiresAt): int
    {
        throw new \LogicException('Not used by this test.');
    }

    public function createRefreshToken(int $accessTokenId, OAuthClient $client, ?int $userId, string $refreshTokenHash, ?int $previousRefreshTokenId, DateTimeImmutable $expiresAt): int
    {
        throw new \LogicException('Not used by this test.');
    }

    public function findUsableRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): ?array
    {
        throw new \LogicException('Not used by this test.');
    }

    public function rotateRefreshToken(int $oldRefreshTokenId, int $newRefreshTokenId, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function markRefreshTokenReuse(string $refreshTokenHash, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function revokeAccessToken(string $accessTokenHash, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function revokeRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function isAccessTokenActive(string $accessTokenHash, DateTimeImmutable $now): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function findActiveUserByAccessTokenHash(string $accessTokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        return null;
    }

    public function findActiveAccessTokenContext(string $accessTokenHash, DateTimeImmutable $now): ?OAuthAccessTokenContext
    {
        if ($accessTokenHash !== hash('sha256', 'client-credentials-token')) {
            return null;
        }

        return new OAuthAccessTokenContext(
            accessTokenId: 601,
            clientId: 501,
            clientIdentifier: 'vocl_conversion_client',
            organizationId: 99,
            user: null,
            scopes: ['attribution.conversion.write.own', 'report.read.own'],
        );
    }

    public function cleanupExpiredTokens(DateTimeImmutable $now, int $retentionSeconds): array
    {
        throw new \LogicException('Not used by this test.');
    }
}
