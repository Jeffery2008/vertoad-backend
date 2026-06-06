<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
}
