<?php

declare(strict_types=1);

namespace VertoAD\Tests\Reporting;

use DI\ContainerBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Action\Reporting\ConversionPathReportAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\Reporting\ConversionPathRepositoryInterface;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\Reporting\ConversionPathReportService;
use VertoAD\Service\TenantAccessService;

final class ConversionPathReportActionTest extends TestCase
{
    public function testRouteReturnsEnvelopeAndScopesToAuthenticatedOrganization(): void
    {
        $repository = new CapturingConversionPathRepository();
        $app = $this->createApp($repository);
        $request = (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                '/api/v1/reports/conversion-paths?portal=advertiser&organization_id=40&campaign_id=30&site_id=10&slot_id=20&from=2026-06-08T00:00:00%2B00:00&to=2026-06-09T00:00:00%2B00:00&limit=5&max_touchpoints=4',
            )
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), 40),
            );

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('data', $decoded);
        self::assertArrayHasKey('error', $decoded);
        self::assertArrayHasKey('meta', $decoded);
        self::assertArrayHasKey('request_id', $decoded);
        self::assertNull($decoded['error']);
        self::assertSame('advertiser', $decoded['data']['portal']);
        self::assertSame(40, $decoded['data']['organization_id']);
        self::assertSame(40, $repository->lastFilters['organization_id'] ?? null);
        self::assertSame(30, $repository->lastFilters['campaign_id'] ?? null);
        self::assertSame(5, $repository->lastFilters['limit'] ?? null);
        self::assertSame(4, $repository->lastFilters['max_touchpoints'] ?? null);
    }

    public function testRouteRejectsOrganizationScopeMismatch(): void
    {
        $app = $this->createApp(new CapturingConversionPathRepository());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/conversion-paths?organization_id=41')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), 40),
            );

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $decoded['error']['code']);
        self::assertSame('organization_id must match the authenticated organization scope.', $decoded['error']['message']);
    }

    public function testRouteRejectsInvalidLimitAndDateFilters(): void
    {
        $app = $this->createApp(new CapturingConversionPathRepository());

        foreach ([
            '/api/v1/reports/conversion-paths?campaign_id=0' => 'campaign_id must be a positive integer.',
            '/api/v1/reports/conversion-paths?limit=0' => 'limit must be between 1 and 100.',
            '/api/v1/reports/conversion-paths?max_touchpoints=26' => 'max_touchpoints must be between 1 and 25.',
            '/api/v1/reports/conversion-paths?from=2026-06-08' => 'from must be an RFC3339 date-time string.',
            '/api/v1/reports/conversion-paths?from=2026-06-09T00:00:00%2B00:00&to=2026-06-08T00:00:00%2B00:00' => 'from must be earlier than to.',
            '/api/v1/reports/conversion-paths?portal=operator' => 'portal must be advertiser or publisher.',
        ] as $uri => $message) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', $uri)
                ->withAttribute(
                    RequestUserContext::ATTRIBUTE,
                    new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), 40),
                );

            $response = $app->handle($request);
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(422, $response->getStatusCode(), $uri);
            self::assertSame('invalid_request', $decoded['error']['code'], $uri);
            self::assertSame($message, $decoded['error']['message'], $uri);
        }
    }

    public function testRouteRejectsNonScalarQueryParameters(): void
    {
        $app = $this->createApp(new CapturingConversionPathRepository());

        foreach ([
            ['from' => ['2026-06-08T00:00:00+00:00'], 'message' => 'from must be an RFC3339 date-time string.'],
            ['limit' => ['5'], 'message' => 'limit must be between 1 and 100.'],
        ] as $query) {
            $message = (string) $query['message'];
            unset($query['message']);
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/v1/reports/conversion-paths')
                ->withQueryParams($query)
                ->withAttribute(
                    RequestUserContext::ATTRIBUTE,
                    new RequestUserContext(new AuthenticatedUser(7, 'owner@example.com', false), 40),
                );

            $response = $app->handle($request);
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertSame('invalid_request', $decoded['error']['code'], $message);
            self::assertSame($message, $decoded['error']['message']);
        }
    }

    public function testRouteAllowsDirectActionUseWithoutAuthenticatedOrganizationContext(): void
    {
        $repository = new CapturingConversionPathRepository();
        $app = $this->createApp($repository);

        $response = $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/reports/conversion-paths?portal=publisher&organization_id=50',
        ));
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('publisher', $decoded['data']['portal']);
        self::assertSame(50, $decoded['data']['organization_id']);
        self::assertSame(50, $repository->lastFilters['organization_id'] ?? null);
    }

    public function testProtectedRouteRequiresBearerToken(): void
    {
        $response = $this->handleProtected(
            $this->createProtectedApp(new CapturingConversionPathRepository()),
            '/api/v1/reports/conversion-paths?organization_id=40',
        );

        self::assertSame(401, $response['status']);
        self::assertSame('authentication_required', $response['body']['error']['code']);
    }

    public function testProtectedRouteRequiresOrganizationScope(): void
    {
        $response = $this->handleProtected(
            $this->createProtectedApp(new CapturingConversionPathRepository()),
            '/api/v1/reports/conversion-paths',
            'member-token',
        );

        self::assertSame(400, $response['status']);
        self::assertSame('organization_scope_required', $response['body']['error']['code']);
        self::assertSame('report.read.own', $response['body']['error']['required_permission']);
    }

    public function testProtectedRouteRequiresReportPermission(): void
    {
        $response = $this->handleProtected(
            $this->createProtectedApp(new CapturingConversionPathRepository(), [
                '7:40' => new OrganizationMembership(40, 7, 'active', ['viewer'], []),
            ]),
            '/api/v1/reports/conversion-paths?organization_id=40',
            'member-token',
        );

        self::assertSame(403, $response['status']);
        self::assertSame('permission_required', $response['body']['error']['code']);
        self::assertSame('report.read.own', $response['body']['error']['required_permission']);
    }

    public function testProtectedRouteAllowsMemberWithReportPermission(): void
    {
        $repository = new CapturingConversionPathRepository();
        $response = $this->handleProtected(
            $this->createProtectedApp($repository, [
                '7:40' => new OrganizationMembership(40, 7, 'active', ['analyst'], ['report.read.own']),
            ]),
            '/api/v1/reports/conversion-paths?organization_id=40',
            'member-token',
        );

        self::assertSame(200, $response['status']);
        self::assertNull($response['body']['error']);
        self::assertSame(40, $repository->lastFilters['organization_id'] ?? null);
    }

    public function testProtectedRouteRejectsOAuthTokenWithoutReportScope(): void
    {
        $response = $this->handleProtected(
            $this->createProtectedApp(new CapturingConversionPathRepository()),
            '/api/v1/reports/conversion-paths',
            'billing-token',
        );

        self::assertSame(403, $response['status']);
        self::assertSame('permission_required', $response['body']['error']['code']);
        self::assertSame('report.read.own', $response['body']['error']['required_permission']);
    }

    public function testProtectedRouteAllowsOAuthReportTokenWithoutOrganizationQuery(): void
    {
        $repository = new CapturingConversionPathRepository();
        $response = $this->handleProtected(
            $this->createProtectedApp($repository),
            '/api/v1/reports/conversion-paths',
            'report-token',
        );

        self::assertSame(200, $response['status']);
        self::assertNull($response['body']['error']);
        self::assertSame(40, $response['body']['data']['organization_id']);
        self::assertSame(40, $repository->lastFilters['organization_id'] ?? null);
    }

    public function testProtectedRouteRejectsOAuthOrganizationScopeMismatch(): void
    {
        $response = $this->handleProtected(
            $this->createProtectedApp(new CapturingConversionPathRepository()),
            '/api/v1/reports/conversion-paths?organization_id=41',
            'report-token',
        );

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_request', $response['body']['error']['code']);
        self::assertSame('organization_id must match the authenticated organization scope.', $response['body']['error']['message']);
    }

    private function createApp(CapturingConversionPathRepository $repository): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            ConversionPathRepositoryInterface::class => static fn (): ConversionPathRepositoryInterface => $repository,
            ConversionPathReportService::class => static fn (
                ConversionPathRepositoryInterface $paths,
            ): ConversionPathReportService => new ConversionPathReportService($paths),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->get('/api/v1/reports/conversion-paths', ConversionPathReportAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    private function createProtectedApp(CapturingConversionPathRepository $repository, array $memberships = []): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            ConversionPathRepositoryInterface::class => static fn (): ConversionPathRepositoryInterface => $repository,
            ConversionPathReportService::class => static fn (
                ConversionPathRepositoryInterface $paths,
            ): ConversionPathReportService => new ConversionPathReportService($paths),
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface => new ConversionPathSessionRepository(),
            OAuthTokenRepositoryInterface::class => static fn (): OAuthTokenRepositoryInterface => new ConversionPathOAuthTokenRepository(),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
                OAuthTokenRepositoryInterface $oauthTokens,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions, $oauthTokens),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            TenantAccessService::class => static fn (): TenantAccessService => new TenantAccessService(
                new ConversionPathMembershipRepository($memberships),
                new PermissionMatcher(),
            ),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->get('/api/v1/reports/conversion-paths', ConversionPathReportAction::class)
            ->add(new RequirePermissionMiddleware(
                $app->getResponseFactory(),
                $container->get(TenantAccessService::class),
                PermissionRequirement::forAuthenticatedOrganization('report.read.own'),
            ))
            ->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private function handleProtected(App $app, string $uri, ?string $bearerToken = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }
}

final class CapturingConversionPathRepository implements ConversionPathRepositoryInterface
{
    /** @var array<string, mixed> */
    public array $lastFilters = [];

    public function findAttributedJourneys(array $filters): array
    {
        $this->lastFilters = $filters;

        return [];
    }
}

final class ConversionPathSessionRepository implements FirstPartySessionRepositoryInterface
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
    }

    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        return $tokenHash === hash('sha256', 'member-token')
            ? new AuthenticatedUser(7, 'analyst@example.com', false)
            : null;
    }

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
    {
        return false;
    }
}

final class ConversionPathOAuthTokenRepository implements OAuthTokenRepositoryInterface
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
        $scopes = match ($accessTokenHash) {
            hash('sha256', 'report-token') => ['report.read.own'],
            hash('sha256', 'billing-token') => ['ledger.read'],
            default => null,
        };

        return $scopes === null ? null : new OAuthAccessTokenContext(
            accessTokenId: 701,
            clientId: 501,
            clientIdentifier: 'vocl_report_client',
            organizationId: 40,
            user: null,
            scopes: $scopes,
        );
    }

    public function cleanupExpiredTokens(DateTimeImmutable $now, int $retentionSeconds): array
    {
        throw new \LogicException('Not used by this test.');
    }
}

final readonly class ConversionPathMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    public function __construct(private array $memberships)
    {
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        return $this->memberships[$userId . ':' . $organizationId] ?? null;
    }

    public function listActiveOrganizationsForUser(int $userId): array
    {
        return [];
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}
