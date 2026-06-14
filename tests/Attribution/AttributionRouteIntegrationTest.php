<?php

declare(strict_types=1);

namespace VertoAD\Tests\Attribution;

use DI\ContainerBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Http\Action\Attribution\ConversionPixelAction;
use VertoAD\Http\Action\Attribution\ServerConversionAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Repository\Attribution\InMemoryAttributionEventRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\Attribution\AttributionService;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class AttributionRouteIntegrationTest extends TestCase
{
    public function testServerApiConversionActionRejectsMissingAuthContext(): void
    {
        $action = new ServerConversionAction(new AttributionService(new InMemoryAttributionEventRepository(), 604800));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/attribution/conversions');

        $response = $action($request, (new ResponseFactory())->createResponse());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('authentication_required', $payload['code'] ?? null);
    }

    public function testServerApiConversionActionRejectsMissingOrganizationScope(): void
    {
        $action = new ServerConversionAction(new AttributionService(new InMemoryAttributionEventRepository(), 604800));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/attribution/conversions')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(7, 'advertiser@example.com', false), null),
            );

        $response = $action($request, (new ResponseFactory())->createResponse());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('organization_scope_required', $payload['code'] ?? null);
    }

    public function testServerApiConversionRouteRequiresBearerToken(): void
    {
        $app = $this->createApp(new InMemoryAttributionEventRepository());

        $response = $this->handleJson($app, 'POST', '/api/v1/attribution/conversions', [
            'event_id' => 'conv-server-auth',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
        ]);

        self::assertSame(401, $response['status']);
        self::assertSame('authentication_required', $response['body']['error']['code']);
    }

    public function testServerApiConversionRouteRequiresConversionWriteScope(): void
    {
        $app = $this->createApp(new InMemoryAttributionEventRepository());

        $response = $this->handleJson($app, 'POST', '/api/v1/attribution/conversions', [
            'event_id' => 'conv-server-scope',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
        ], 'report-only-token');

        self::assertSame(403, $response['status']);
        self::assertSame('permission_required', $response['body']['error']['code']);
        self::assertSame('attribution.conversion.write.own', $response['body']['error']['required_permission']);
    }

    public function testServerApiConversionRouteReturnsEnvelope(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision(), 'click-1', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $app = $this->createApp($repository);

        $response = $this->handleJson($app, 'POST', '/api/v1/attribution/conversions', [
            'event_id' => 'conv-server-1',
            'viewer_id' => 'viewer-1',
            'conversion_name' => 'purchase',
            'value_points' => 9900,
            'occurred_at' => '2026-06-08T11:00:00+00:00',
        ], 'conversion-token');

        self::assertSame(201, $response['status']);
        self::assertArrayHasKey('data', $response['body']);
        self::assertNull($response['body']['error']);
        self::assertSame('server_api', $response['body']['data']['source']);
        self::assertSame(99, $response['body']['data']['organization_id']);
        self::assertSame(501, $response['body']['data']['oauth_client_id']);
        self::assertNull($response['body']['data']['recorded_by_user_id']);
        self::assertSame('2026-06-08T11:00:00+00:00', $response['body']['data']['occurred_at']);
        self::assertTrue($response['body']['data']['attribution']['attributed']);
        self::assertSame('click-1', $response['body']['data']['attribution']['click_event_id']);
    }

    public function testPixelConversionRouteSupportsQueryContract(): void
    {
        $repository = new InMemoryAttributionEventRepository();
        $repository->recordClick($this->decision(), 'click-1', new DateTimeImmutable('2026-06-08T10:00:00+00:00'));
        $app = $this->createApp($repository);

        $response = $this->handleJson(
            $app,
            'GET',
            '/api/v1/attribution/pixel?event_id=conv-pixel-1&viewer_id=viewer-1&conversion_name=signup&value_points=0&occurred_at=2026-06-08T11%3A00%3A00%2B00%3A00&window_seconds=7200',
        );

        self::assertSame(201, $response['status']);
        self::assertSame('browser_pixel', $response['body']['data']['source']);
        self::assertSame(7200, $response['body']['data']['attribution']['window_seconds']);
        self::assertTrue($response['body']['data']['attribution']['attributed']);
    }

    public function testConversionRouteRejectsInvalidPayloadWithEnvelope(): void
    {
        $app = $this->createApp(new InMemoryAttributionEventRepository());

        $response = $this->handleJson($app, 'POST', '/api/v1/attribution/conversions', [
            'event_id' => 'conv-invalid',
            'viewer_id' => 'viewer-1',
            'conversion_name' => '',
        ], 'conversion-token');

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_request', $response['body']['error']['code']);
    }

    public function testPixelConversionRouteRejectsInvalidPayloadWithEnvelope(): void
    {
        $app = $this->createApp(new InMemoryAttributionEventRepository());

        $response = $this->handleJson($app, 'GET', '/api/v1/attribution/pixel?event_id=conv-invalid&viewer_id=viewer-1&conversion_name=');

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_request', $response['body']['error']['code']);
    }

    private function createApp(InMemoryAttributionEventRepository $repository): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            AttributionService::class => static fn (): AttributionService => new AttributionService($repository, 604800),
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface => new AttributionNullSessionRepository(),
            OAuthTokenRepositoryInterface::class => static fn (): OAuthTokenRepositoryInterface => new AttributionFixedOAuthTokenRepository(),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
                OAuthTokenRepositoryInterface $oauthTokens,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions, $oauthTokens),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            TenantAccessService::class => static fn (): TenantAccessService => new TenantAccessService(
                new AttributionNoMembershipRepository(),
                new PermissionMatcher(),
            ),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->post('/api/v1/attribution/conversions', ServerConversionAction::class)
            ->add(new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                \VertoAD\Http\Auth\PermissionRequirement::forOrganization('attribution.conversion.write.own'),
            ))
            ->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/attribution/pixel', ConversionPixelAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>}
     */
    private function handleJson(App $app, string $method, string $uri, ?array $payload = null, ?string $bearerToken = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }
        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }

    private function decision(): AdDecision
    {
        return new AdDecision(
            decisionId: 'decision-1',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-1',
            filled: true,
            reason: null,
            iframeHtml: '<iframe></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 99,
            publisherOrganizationId: 50,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            landingUrl: 'https://advertiser.example/landing',
            decidedAt: new DateTimeImmutable('2026-06-08T09:00:00+00:00'),
        );
    }
}

final class AttributionNullSessionRepository implements FirstPartySessionRepositoryInterface
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
    }

    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        return null;
    }

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
    {
        return false;
    }
}

final class AttributionFixedOAuthTokenRepository implements OAuthTokenRepositoryInterface
{
    public function createAuthorizationCode(OAuthClient $client, int $userId, ?int $organizationId, string $codeHash, string $redirectUri, array $scopes, string $codeChallenge, string $codeChallengeMethod, DateTimeImmutable $expiresAt): int
    {
        throw new \LogicException('Not used by this test.');
    }

    public function consumeAuthorizationCode(string $codeHash, DateTimeImmutable $now): ?array
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

    public function rotateRefreshToken(int $oldRefreshTokenId, int $newRefreshTokenId, DateTimeImmutable $now): void
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
        $scopes = match ($accessTokenHash) {
            hash('sha256', 'conversion-token') => ['attribution.conversion.write.own', 'report.read.own'],
            hash('sha256', 'report-only-token') => ['report.read.own'],
            default => null,
        };

        return $scopes === null ? null : new OAuthAccessTokenContext(
            accessTokenId: 601,
            clientId: 501,
            clientIdentifier: 'vocl_conversion_client',
            organizationId: 99,
            user: null,
            scopes: $scopes,
        );
    }

    public function cleanupExpiredTokens(DateTimeImmutable $now, int $retentionSeconds): array
    {
        throw new \LogicException('Not used by this test.');
    }
}

final class AttributionNoMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function findActiveMembership(int $userId, int $organizationId): ?\VertoAD\Domain\Auth\OrganizationMembership
    {
        return null;
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}
