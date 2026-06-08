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
