<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use DateTimeImmutable;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\Support\InMemorySupportTicketRepository;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\Support\SupportTicketService;
use VertoAD\Service\TenantAccessService;

final class SupportTicketListRouteMiddlewareTest extends TestCase
{
    public function testAuthenticatedInvalidOrganizationQueryReturnsValidationEnvelopeBeforePermissionScopeError(): void
    {
        $app = $this->createRoutedApp();

        foreach (
            [
                '/api/v1/support/tickets?organization_id[]=101',
                '/api/v1/support/tickets?organization_id=0',
                '/api/v1/support/tickets?organization_id=-1',
                '/api/v1/support/tickets?organization_id=not-a-number',
            ] as $uri
        ) {
            $response = $this->handle($app, $uri, 'fixed-token', 'req-invalid-support-org');

            self::assertSame(422, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
            self::assertNull($response['body']['data']);
            self::assertSame('invalid_request', $response['body']['error']['code']);
            self::assertStringContainsString('organization_id', $response['body']['error']['message']);
            self::assertStringContainsString('positive integer', $response['body']['error']['message']);
            self::assertSame('req-invalid-support-org', $response['body']['request_id']);
        }
    }

    public function testUnauthenticatedInvalidOrganizationQueryStillRequiresAuthentication(): void
    {
        $response = $this->handle(
            $this->createRoutedApp(),
            '/api/v1/support/tickets?organization_id=not-a-number',
            requestId: 'req-unauthenticated-support-org',
        );

        self::assertSame(401, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('authentication_required', $response['body']['error']['code']);
        self::assertSame('req-unauthenticated-support-org', $response['body']['request_id']);
    }

    public function testMissingOrganizationQueryContinuesToPermissionScopeErrorForAuthenticatedUser(): void
    {
        $response = $this->handle(
            $this->createRoutedApp(),
            '/api/v1/support/tickets',
            'fixed-token',
        );

        self::assertSame(400, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertSame('organization_scope_required', $response['body']['error']['code']);
    }

    public function testValidOrganizationQueryContinuesThroughPermissionMiddleware(): void
    {
        $response = $this->handle(
            $this->createRoutedApp(),
            '/api/v1/support/tickets?organization_id=101',
            'fixed-token',
        );

        self::assertSame(200, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertNull($response['body']['error']);
        self::assertSame([], $response['body']['data']['tickets']);
    }

    public function testIntegerOrganizationQueryContinuesThroughPermissionMiddleware(): void
    {
        $response = $this->handle(
            $this->createRoutedApp(),
            '/api/v1/support/tickets',
            'fixed-token',
            queryParams: ['organization_id' => 101],
        );

        self::assertSame(200, $response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR));
        self::assertNull($response['body']['error']);
        self::assertSame([], $response['body']['data']['tickets']);
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function handle(
        App $app,
        string $uri,
        ?string $bearerToken = null,
        string $requestId = 'req-support-route',
        ?array $queryParams = null,
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withHeader('X-Request-Id', $requestId);

        if ($queryParams !== null) {
            $request = $request->withQueryParams($queryParams);
        }

        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
        ];
    }

    private function createRoutedApp(): App
    {
        $tickets = new InMemorySupportTicketRepository();
        $audit = new AuditLogService(new SupportTicketListRouteAuditRepository());

        $container = (new ContainerBuilder())->addDefinitions([
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new SupportTicketListRouteSessionRepository(),
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new SupportTicketListRouteMembershipRepository(),
            TenantAccessService::class => static fn (
                OrganizationMembershipRepositoryInterface $memberships,
            ): TenantAccessService => new TenantAccessService($memberships, new PermissionMatcher()),
            SupportTicketRepositoryInterface::class => static fn (): SupportTicketRepositoryInterface => $tickets,
            SupportTicketService::class => static fn (): SupportTicketService => new SupportTicketService($tickets, $audit),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();

        $routes = require dirname(__DIR__) . '/config/routes.php';
        $routes($app);

        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }
}

final class SupportTicketListRouteSessionRepository implements FirstPartySessionRepositoryInterface
{
    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
    {
    }

    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
    {
        if ($tokenHash !== hash('sha256', 'fixed-token')) {
            return null;
        }

        return new AuthenticatedUser(501, 'member@example.com', false);
    }

    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
    {
        return false;
    }
}

final class SupportTicketListRouteMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        if ($userId !== 501 || $organizationId !== 101) {
            return null;
        }

        return new OrganizationMembership(101, 501, 'active', ['publisher-support'], ['support.ticket.read.own']);
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}

final class SupportTicketListRouteAuditRepository implements AuditLogRepositoryInterface
{
    public function append(AuditLogEntry $entry): void
    {
    }
}
