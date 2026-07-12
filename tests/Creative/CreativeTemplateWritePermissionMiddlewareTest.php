<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\CreativeTemplateWritePermissionMiddleware;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\TenantAccessService;

final class CreativeTemplateWritePermissionMiddlewareTest extends TestCase
{
    public function testRejectsMissingIdentityAndNonObjectPayloads(): void
    {
        $missingIdentity = $this->process(null, ['scope' => 'organization', 'organization_id' => 101]);
        $missingPayload = $this->payload($missingIdentity);

        self::assertSame(401, $missingIdentity->getStatusCode());
        self::assertSame('authentication_required', $missingPayload['code'] ?? null);

        $invalidPayload = $this->process(
            new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101),
            null,
        );
        $invalidPayloadBody = $this->payload($invalidPayload);

        self::assertSame(422, $invalidPayload->getStatusCode());
        self::assertSame('invalid_request', $invalidPayloadBody['code'] ?? null);
    }

    public function testPlatformTemplatesAllowSuperAdminsAndDenyUnscopedNonAdmins(): void
    {
        $allowed = $this->process(
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
            ['scope' => 'platform', 'name' => 'Platform template'],
        );
        $allowedPayload = $this->payload($allowed);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('permission-ok', $allowedPayload['status'] ?? null);

        $denied = $this->process(
            new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), null),
            ['scope' => 'platform', 'name' => 'Platform template'],
        );
        $deniedPayload = $this->payload($denied);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('permission_required', $deniedPayload['code'] ?? null);
        self::assertSame('creative.template.manage.platform', $deniedPayload['required_permission'] ?? null);
    }

    public function testPlatformTemplatesUseTenantPermissionForScopedStaff(): void
    {
        $allowed = $this->process(
            new RequestUserContext(new AuthenticatedUser(2, 'ops@example.com', false), 101),
            ['scope' => 'platform', 'name' => 'Platform template'],
            [
                '2:101' => new OrganizationMembership(
                    organizationId: 101,
                    userId: 2,
                    status: 'active',
                    roleSlugs: ['platform-creative'],
                    permissions: ['creative.template.manage.platform'],
                ),
            ],
        );
        $allowedPayload = $this->payload($allowed);

        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame('permission-ok', $allowedPayload['status'] ?? null);

        $denied = $this->process(
            new RequestUserContext(new AuthenticatedUser(3, 'viewer@example.com', false), 101),
            ['scope' => 'platform', 'name' => 'Platform template'],
            [
                '3:101' => new OrganizationMembership(
                    organizationId: 101,
                    userId: 3,
                    status: 'active',
                    roleSlugs: ['viewer'],
                    permissions: ['creative.template.write.own'],
                ),
            ],
        );
        $deniedPayload = $this->payload($denied);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('permission_required', $deniedPayload['code'] ?? null);
        self::assertSame('creative.template.manage.platform', $deniedPayload['required_permission'] ?? null);
    }

    public function testOAuthScopesAreRequiredBeforeTemplateWritePermissionChecks(): void
    {
        $denied = $this->process(
            new RequestUserContext(
                organizationId: 101,
                oauthToken: new OAuthAccessTokenContext(
                    accessTokenId: 601,
                    clientId: 501,
                    clientIdentifier: 'vocl_creative',
                    organizationId: 101,
                    user: null,
                    scopes: ['creative.design.write.own'],
                ),
            ),
            ['scope' => 'organization', 'organization_id' => 101],
        );
        $payload = $this->payload($denied);

        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('permission_required', $payload['code'] ?? null);
        self::assertSame('creative.template.write.own', $payload['required_permission'] ?? null);
    }

    public function testOrganizationTemplatesRequireScopeAndMatchOAuthOrganization(): void
    {
        $missingScope = $this->process(
            new RequestUserContext(oauthToken: new OAuthAccessTokenContext(
                accessTokenId: 602,
                clientId: 502,
                clientIdentifier: 'vocl_creative',
                organizationId: null,
                user: null,
                scopes: ['creative.template.write.own'],
            )),
            ['scope' => 'organization'],
        );
        $missingScopePayload = $this->payload($missingScope);

        self::assertSame(400, $missingScope->getStatusCode());
        self::assertSame('organization_scope_required', $missingScopePayload['code'] ?? null);
        self::assertSame('creative.template.write.own', $missingScopePayload['required_permission'] ?? null);

        $mismatch = $this->process(
            new RequestUserContext(
                organizationId: 101,
                oauthToken: new OAuthAccessTokenContext(
                    accessTokenId: 603,
                    clientId: 503,
                    clientIdentifier: 'vocl_creative',
                    organizationId: 101,
                    user: null,
                    scopes: ['creative.template.write.own'],
                ),
            ),
            ['scope' => 'organization', 'organization_id' => 202],
        );
        $mismatchPayload = $this->payload($mismatch);

        self::assertSame(403, $mismatch->getStatusCode());
        self::assertSame('organization_scope_mismatch', $mismatchPayload['code'] ?? null);
        self::assertSame('creative.template.write.own', $mismatchPayload['required_permission'] ?? null);
    }

    public function testOrganizationTemplatesAllowOAuthOnlyAndDenyMembersMissingPermission(): void
    {
        $oauthOnly = $this->process(
            new RequestUserContext(
                organizationId: 101,
                oauthToken: new OAuthAccessTokenContext(
                    accessTokenId: 604,
                    clientId: 504,
                    clientIdentifier: 'vocl_creative',
                    organizationId: 101,
                    user: null,
                    scopes: ['creative.template.*'],
                ),
            ),
            ['scope' => 'organization', 'organization_id' => 101],
        );
        $oauthOnlyPayload = $this->payload($oauthOnly);

        self::assertSame(200, $oauthOnly->getStatusCode());
        self::assertSame('permission-ok', $oauthOnlyPayload['status'] ?? null);

        $deniedMember = $this->process(
            new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101),
            ['scope' => 'organization', 'organization_id' => 101],
            [
                '7:101' => new OrganizationMembership(
                    organizationId: 101,
                    userId: 7,
                    status: 'active',
                    roleSlugs: ['viewer'],
                    permissions: ['creative.template.read.own'],
                ),
            ],
        );
        $deniedPayload = $this->payload($deniedMember);

        self::assertSame(403, $deniedMember->getStatusCode());
        self::assertSame('permission_required', $deniedPayload['code'] ?? null);
        self::assertSame('creative.template.write.own', $deniedPayload['required_permission'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, OrganizationMembership> $memberships
     */
    private function process(?RequestUserContext $context, ?array $payload, array $memberships = []): ResponseInterface
    {
        $responseFactory = new ResponseFactory();
        $middleware = new CreativeTemplateWritePermissionMiddleware(
            $responseFactory,
            new TenantAccessService(new CreativeTemplatePermissionMembershipRepository($memberships), new PermissionMatcher()),
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/creative/templates');
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($context !== null) {
            $request = $request->withAttribute(RequestUserContext::ATTRIBUTE, $context);
        }

        return $middleware->process($request, new CreativeTemplatePermissionOkHandler($responseFactory));
    }

    /** @return array<string, mixed> */
    private function payload(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class CreativeTemplatePermissionOkHandler implements RequestHandlerInterface
{
    public function __construct(private readonly ResponseFactory $responseFactory)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(['status' => 'permission-ok'], JSON_THROW_ON_ERROR));

        return $response;
    }
}

final class CreativeTemplatePermissionMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    /** @param array<string, OrganizationMembership> $memberships */
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
