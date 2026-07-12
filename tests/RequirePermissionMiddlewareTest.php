<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class RequirePermissionMiddlewareTest extends TestCase
{
    public function testAllowsUserWithRequiredPermissionInTenant(): void
    {
        $response = $this->handleProbe(
            new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), 10),
            [
                '20:10' => new OrganizationMembership(10, 20, 'active', ['billing'], [Permission::LedgerRead]),
            ],
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('permission-ok', $payload['data']['status'] ?? null);
        self::assertNull($payload['error'] ?? null);
    }

    public function testDeniesMissingIdentityWithEnvelopeAndRequestId(): void
    {
        $response = $this->handleProbe(null, [], 'request-missing-identity');
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('authentication_required', $payload['error']['code'] ?? null);
        self::assertSame('request-missing-identity', $payload['request_id'] ?? null);
        self::assertSame('request-missing-identity', $response->getHeaderLine('X-Request-Id'));
    }

    public function testDeniesUserMissingRequiredPermission(): void
    {
        $response = $this->handleProbe(
            new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), 10),
            [
                '20:10' => new OrganizationMembership(10, 20, 'active', ['publisher'], [Permission::PublisherSitesRead]),
            ],
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $payload['error']['code'] ?? null);
        self::assertSame(Permission::LedgerRead, $payload['error']['required_permission'] ?? null);
    }

    public function testDeniesAuthenticatedUserWhenOrganizationScopeIsMissing(): void
    {
        $response = $this->handleProbeWithoutRouteOrganization(
            new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), null),
            [],
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('organization_scope_required', $payload['error']['code'] ?? null);
        self::assertSame(Permission::LedgerRead, $payload['error']['required_permission'] ?? null);
    }

    public function testDeniesCrossTenantAccess(): void
    {
        $response = $this->handleProbe(
            new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), 10),
            [
                '20:11' => new OrganizationMembership(11, 20, 'active', ['billing'], [Permission::LedgerRead]),
            ],
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('membership_required', $payload['error']['code'] ?? null);
    }

    public function testAllowsSuperAdminAcrossTenantWithoutMembership(): void
    {
        $response = $this->handleProbe(
            new RequestUserContext(new AuthenticatedUser(1, 'admin@example.com', true), 999),
            [],
        );
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('permission-ok', $payload['data']['status'] ?? null);
    }

    public function testPlatformPermissionAllowsSuperAdminsWithoutOrganizationScope(): void
    {
        $allowed = $this->processPlatformPermission(
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
        $allowedPayload = json_decode((string) $allowed->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('permission-ok', $allowedPayload['status'] ?? null);
    }

    public function testPlatformPermissionAllowsScopedPlatformStaffWithPermission(): void
    {
        $allowed = $this->processPlatformPermission(
            new RequestUserContext(new AuthenticatedUser(2, 'ops@example.com', false), 10),
            [
                '2:10' => new OrganizationMembership(10, 2, 'active', ['ops'], ['ops.dashboard.read.platform']),
            ],
        );
        $allowedPayload = json_decode((string) $allowed->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('permission-ok', $allowedPayload['status'] ?? null);
    }

    public function testPlatformPermissionDeniesScopedUserMissingPermission(): void
    {
        $denied = $this->processPlatformPermission(
            new RequestUserContext(new AuthenticatedUser(2, 'ops@example.com', false), 10),
            [
                '2:10' => new OrganizationMembership(10, 2, 'active', ['ops'], ['ops.error_log.read_redacted.platform']),
            ],
        );
        $deniedPayload = json_decode((string) $denied->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('permission_required', $deniedPayload['code'] ?? null);
        self::assertSame('ops.dashboard.read.platform', $deniedPayload['required_permission'] ?? null);
    }

    public function testPlatformPermissionDeniesOAuthClientEvenWithMatchingScope(): void
    {
        $denied = $this->processPlatformPermission(new RequestUserContext(
            organizationId: 10,
            oauthToken: new OAuthAccessTokenContext(
                accessTokenId: 601,
                clientId: 501,
                clientIdentifier: 'vocl_ops_client',
                organizationId: 10,
                user: null,
                scopes: ['ops.dashboard.read.platform'],
            ),
        ));
        $payload = json_decode((string) $denied->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('permission_required', $payload['code'] ?? null);
        self::assertSame('ops.dashboard.read.platform', $payload['required_permission'] ?? null);
    }

    public function testPlatformPermissionRequiresScopeForNonSuperAdmins(): void
    {
        $denied = $this->processPlatformPermission(
            new RequestUserContext(new AuthenticatedUser(2, 'ops@example.com', false), null),
        );
        $payload = json_decode((string) $denied->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $denied->getStatusCode());
        self::assertSame('organization_scope_required', $payload['code'] ?? null);
        self::assertSame('ops.dashboard.read.platform', $payload['required_permission'] ?? null);
    }

    public function testUserBoundOauthTokenMustContainRequiredScopeBeforeMembershipCheck(): void
    {
        $responseFactory = new ResponseFactory();
        $user = new AuthenticatedUser(20, 'member@example.com', false);
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            new TenantAccessService(new PermissionMiddlewareMembershipRepository([
                '20:10' => new OrganizationMembership(10, 20, 'active', ['reporter'], ['report.read.own']),
            ]), new PermissionMatcher()),
            PermissionRequirement::forAuthenticatedOrganization('report.read.own'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/dashboard')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(
                    user: $user,
                    organizationId: 10,
                    oauthToken: new OAuthAccessTokenContext(
                        accessTokenId: 600,
                        clientId: 500,
                        clientIdentifier: 'vocl_user_report_client',
                        organizationId: 10,
                        user: $user,
                        scopes: ['campaign.read.own'],
                    ),
                ),
            );

        $response = $middleware->process($request, new PermissionOkHandler($responseFactory));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $payload['code'] ?? null);
        self::assertSame('report.read.own', $payload['required_permission'] ?? null);
    }

    public function testDeniesOAuthClientWhenRouteOrganizationDoesNotMatchTokenOrganization(): void
    {
        $response = $this->handleProbe(
            new RequestUserContext(
                organizationId: 99,
                oauthToken: new OAuthAccessTokenContext(
                    accessTokenId: 602,
                    clientId: 502,
                    clientIdentifier: 'vocl_ledger_client',
                    organizationId: 99,
                    user: null,
                    scopes: [Permission::LedgerRead],
                ),
            ),
            [],
        );
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('organization_scope_mismatch', $payload['error']['code'] ?? null);
        self::assertSame(Permission::LedgerRead, $payload['error']['required_permission'] ?? null);
    }

    public function testDeniesOAuthClientWhenTokenOrganizationDoesNotMatchAuthenticatedOrganizationRequirement(): void
    {
        $responseFactory = new ResponseFactory();
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            new TenantAccessService(new PermissionMiddlewareMembershipRepository([]), new PermissionMatcher()),
            PermissionRequirement::forAuthenticatedOrganization(Permission::LedgerRead),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/example')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(
                    organizationId: 10,
                    oauthToken: new OAuthAccessTokenContext(
                        accessTokenId: 603,
                        clientId: 503,
                        clientIdentifier: 'vocl_ledger_mismatch',
                        organizationId: 99,
                        user: null,
                        scopes: [Permission::LedgerRead],
                    ),
                ),
            );

        $response = $middleware->process($request, new PermissionOkHandler($responseFactory));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('organization_scope_mismatch', $payload['code'] ?? null);
        self::assertSame(Permission::LedgerRead, $payload['required_permission'] ?? null);
    }

    public function testMachineTokenRequiresExactAdvertiserOpenApiScope(): void
    {
        $responseFactory = new ResponseFactory();
        $tenantAccess = new TenantAccessService(new PermissionMiddlewareMembershipRepository([]), new PermissionMatcher());
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            $tenantAccess,
            PermissionRequirement::forAuthenticatedOrganization('report.read.own'),
        );

        foreach ([
            [['report.read.own'], 200],
            [['*'], 403],
            [['campaign.read.own'], 403],
        ] as [$scopes, $expectedStatus]) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/v1/reports/dashboard')
                ->withAttribute(
                    RequestUserContext::ATTRIBUTE,
                    new RequestUserContext(
                        organizationId: 10,
                        oauthToken: new OAuthAccessTokenContext(
                            accessTokenId: 604,
                            clientId: 504,
                            clientIdentifier: 'vocl_report_client',
                            organizationId: 10,
                            user: null,
                            scopes: $scopes,
                        ),
                    ),
                );

            $response = $middleware->process($request, new PermissionOkHandler($responseFactory));
            self::assertSame($expectedStatus, $response->getStatusCode());
        }

        $crossOrganization = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/dashboard')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(
                    organizationId: 11,
                    oauthToken: new OAuthAccessTokenContext(
                        accessTokenId: 606,
                        clientId: 506,
                        clientIdentifier: 'vocl_report_client',
                        organizationId: 10,
                        user: null,
                        scopes: ['report.read.own'],
                    ),
                ),
            );
        $crossOrganizationResponse = $middleware->process($crossOrganization, new PermissionOkHandler($responseFactory));
        $crossOrganizationPayload = json_decode((string) $crossOrganizationResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(403, $crossOrganizationResponse->getStatusCode());
        self::assertSame('organization_scope_mismatch', $crossOrganizationPayload['code'] ?? null);
    }

    public function testMachineTokenCannotUseOrganizationManagementScopeEvenWhenLegacyTokenContainsIt(): void
    {
        $responseFactory = new ResponseFactory();
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            new TenantAccessService(new PermissionMiddlewareMembershipRepository([]), new PermissionMatcher()),
            PermissionRequirement::forAuthenticatedOrganization('organizations.members.manage'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/organizations/10/members')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(
                    organizationId: 10,
                    oauthToken: new OAuthAccessTokenContext(
                        accessTokenId: 605,
                        clientId: 505,
                        clientIdentifier: 'vocl_legacy_management_client',
                        organizationId: 10,
                        user: null,
                        scopes: ['organizations.members.manage'],
                    ),
                ),
            );

        $response = $middleware->process($request, new PermissionOkHandler($responseFactory));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('permission_required', $payload['code'] ?? null);
        self::assertSame('organizations.members.manage', $payload['required_permission'] ?? null);
    }

    public function testAllowsIntegerRouteOrganizationAttribute(): void
    {
        $response = $this->processDirectlyWithRouteAttribute(10);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('permission-ok', $payload['status'] ?? null);
    }

    public function testAllowsStringRouteOrganizationAttribute(): void
    {
        $response = $this->processDirectlyWithRouteAttribute('10');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('permission-ok', $payload['status'] ?? null);
    }

    public function testAllowsBodyOrganizationScopeWhenRouteAndContextAreUnscoped(): void
    {
        $response = $this->processDirectlyWithParsedBody(['organization_id' => 10]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('permission-ok', $payload['status'] ?? null);
    }

    public function testCanAuthorizeAgainstContextOrganizationWithoutConsumingQueryScope(): void
    {
        $responseFactory = new ResponseFactory();
        $tenantAccess = new TenantAccessService(new PermissionMiddlewareMembershipRepository([
            '20:10' => new OrganizationMembership(10, 20, 'active', ['billing'], [Permission::LedgerRead]),
        ]), new PermissionMatcher());
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            $tenantAccess,
            PermissionRequirement::forAuthenticatedOrganization(Permission::LedgerRead),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/example?organization_id=11')
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), 10),
            );

        $response = $middleware->process($request, new PermissionOkHandler($responseFactory));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('permission-ok', $payload['status'] ?? null);
    }

    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    private function handleProbe(
        ?RequestUserContext $context,
        array $memberships,
        string $requestId = 'request-123',
    ): ResponseInterface {
        $app = new App(new ResponseFactory());
        $responseFactory = $app->getResponseFactory();
        $tenantAccess = new TenantAccessService(new PermissionMiddlewareMembershipRepository($memberships), new PermissionMatcher());

        $app->get('/api/v1/orgs/{organization_id}/permission-probe', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            $response->getBody()->write(json_encode(['status' => 'permission-ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->add(new RequirePermissionMiddleware(
            $responseFactory,
            $tenantAccess,
            PermissionRequirement::forOrganization(Permission::LedgerRead),
        ));

        $app->add(new ApiEnvelopeMiddleware($responseFactory));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/orgs/10/permission-probe')
            ->withHeader('X-Request-Id', $requestId);

        if ($context !== null) {
            $request = $request->withAttribute(RequestUserContext::ATTRIBUTE, $context);
        }

        return $app->handle($request);
    }

    private function processDirectlyWithRouteAttribute(int|string $organizationId): ResponseInterface
    {
        $responseFactory = new ResponseFactory();
        $tenantAccess = new TenantAccessService(new PermissionMiddlewareMembershipRepository([
            '20:10' => new OrganizationMembership(10, 20, 'active', ['billing'], [Permission::LedgerRead]),
        ]), new PermissionMatcher());
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            $tenantAccess,
            PermissionRequirement::forOrganization(Permission::LedgerRead),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/orgs/10/permission-probe')
            ->withAttribute('organization_id', $organizationId)
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), null),
            );

        return $middleware->process($request, new PermissionOkHandler($responseFactory));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processDirectlyWithParsedBody(array $payload): ResponseInterface
    {
        $responseFactory = new ResponseFactory();
        $tenantAccess = new TenantAccessService(new PermissionMiddlewareMembershipRepository([
            '20:10' => new OrganizationMembership(10, 20, 'active', ['billing'], [Permission::LedgerRead]),
        ]), new PermissionMatcher());
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            $tenantAccess,
            PermissionRequirement::forOrganization(Permission::LedgerRead),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/permission-probe')
            ->withParsedBody($payload)
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(20, 'member@example.com', false), null),
            );

        return $middleware->process($request, new PermissionOkHandler($responseFactory));
    }

    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    private function processPlatformPermission(RequestUserContext $context, array $memberships = []): ResponseInterface
    {
        $responseFactory = new ResponseFactory();
        $middleware = new RequirePermissionMiddleware(
            $responseFactory,
            new TenantAccessService(new PermissionMiddlewareMembershipRepository($memberships), new PermissionMatcher()),
            PermissionRequirement::forPlatform('ops.dashboard.read.platform'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/operations/summary')
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        return $middleware->process($request, new PermissionOkHandler($responseFactory));
    }

    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    private function handleProbeWithoutRouteOrganization(
        RequestUserContext $context,
        array $memberships,
    ): ResponseInterface {
        $app = new App(new ResponseFactory());
        $responseFactory = $app->getResponseFactory();
        $tenantAccess = new TenantAccessService(new PermissionMiddlewareMembershipRepository($memberships), new PermissionMatcher());

        $app->get('/api/v1/permission-probe', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            $response->getBody()->write(json_encode(['status' => 'permission-ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->add(new RequirePermissionMiddleware(
            $responseFactory,
            $tenantAccess,
            PermissionRequirement::forOrganization(Permission::LedgerRead),
        ));

        $app->add(new ApiEnvelopeMiddleware($responseFactory));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/permission-probe')
            ->withHeader('X-Request-Id', 'missing-scope-request')
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        return $app->handle($request);
    }
}

/**
 * @phpstan-type MembershipMap array<string, OrganizationMembership>
 */
final class PermissionMiddlewareMembershipRepository implements \VertoAD\Repository\OrganizationMembershipRepositoryInterface
{
    /**
     * @param array<string, OrganizationMembership> $memberships
     */
    public function __construct(private readonly array $memberships)
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

final readonly class PermissionOkHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactory $responseFactory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200);
        $response->getBody()->write(json_encode(['status' => 'permission-ok'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
