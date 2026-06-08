<?php

declare(strict_types=1);

namespace VertoAD\Tests\Security;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RateLimitMiddleware;
use VertoAD\Infrastructure\Security\InMemoryRateLimitStore;
use VertoAD\Infrastructure\Security\RateLimitDimensions;
use VertoAD\Infrastructure\Security\RateLimiter;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class RateLimitTest extends TestCase
{
    public function testLimiterTracksSeparateDimensionKeysAndRetryMetadata(): void
    {
        $limiter = new RateLimiter(new InMemoryRateLimitStore());
        $policy = new RateLimitPolicy(limit: 2, windowSeconds: 60);
        $now = new DateTimeImmutable('2026-06-08T00:00:00Z');

        $first = $limiter->hit(new RateLimitDimensions(ip: '203.0.113.10', endpoint: 'POST:/login'), $policy, $now);
        $second = $limiter->hit(new RateLimitDimensions(ip: '203.0.113.10', endpoint: 'POST:/login'), $policy, $now);
        $third = $limiter->hit(new RateLimitDimensions(ip: '203.0.113.10', endpoint: 'POST:/login'), $policy, $now);
        $otherIp = $limiter->hit(new RateLimitDimensions(ip: '203.0.113.11', endpoint: 'POST:/login'), $policy, $now);

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertFalse($third->allowed);
        self::assertSame(60, $third->retryAfterSeconds);
        self::assertSame(2, $third->remaining);
        self::assertTrue($otherIp->allowed);
    }

    public function testLimiterSeparatesUserOrganizationClientAndEndpointDimensions(): void
    {
        $limiter = new RateLimiter(new InMemoryRateLimitStore());
        $policy = new RateLimitPolicy(limit: 1, windowSeconds: 30);
        $now = new DateTimeImmutable('2026-06-08T00:00:00Z');

        $blocked = $limiter->hit(new RateLimitDimensions(
            ip: '203.0.113.10',
            userId: 7,
            organizationId: 99,
            clientId: 'client-a',
            endpoint: 'POST:/billing',
        ), $policy, $now);
        $sameKey = $limiter->hit(new RateLimitDimensions(
            ip: '203.0.113.10',
            userId: 7,
            organizationId: 99,
            clientId: 'client-a',
            endpoint: 'POST:/billing',
        ), $policy, $now);
        $otherClient = $limiter->hit(new RateLimitDimensions(
            ip: '203.0.113.10',
            userId: 7,
            organizationId: 99,
            clientId: 'client-b',
            endpoint: 'POST:/billing',
        ), $policy, $now);

        self::assertTrue($blocked->allowed);
        self::assertFalse($sameKey->allowed);
        self::assertTrue($otherClient->allowed);
    }

    public function testPolicyRejectsNonPositiveLimitsAndWindows(): void
    {
        try {
            new RateLimitPolicy(limit: 0, windowSeconds: 60);
            self::fail('A zero request limit must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Rate limit must be at least 1.', $exception->getMessage());
        }

        try {
            new RateLimitPolicy(limit: 1, windowSeconds: 0);
            self::fail('A zero-second window must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Rate limit window must be at least 1 second.', $exception->getMessage());
        }
    }

    public function testMiddlewareReturnsEnvelopeHeadersAndAuditEventWhenLimited(): void
    {
        $auditRepository = new RateLimitAuditRepository();
        $app = $this->createProtectedApp(new RateLimiter(new InMemoryRateLimitStore()), new AuditLogService($auditRepository));

        $first = $this->handle($app, 'rate-limit-request-1');
        $second = $this->handle($app, 'rate-limit-request-2');
        $payload = json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame('rate_limited', $payload['error']['code']);
        self::assertSame('rate-limit-request-2', $payload['request_id']);
        self::assertSame('30', $second->getHeaderLine('Retry-After'));
        self::assertSame('1', $second->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('security.rate_limit.denied', $auditRepository->entries[0]->action ?? null);
    }

    public function testMiddlewareUsesDefaultClockWhenInjectedClockIsInvalid(): void
    {
        $app = $this->createProtectedApp(
            new RateLimiter(new InMemoryRateLimitStore()),
            new AuditLogService(new RateLimitAuditRepository()),
            static fn (): string => 'not-a-date',
        );

        $before = time();
        $response = $this->handle($app, 'rate-limit-default-clock');
        $after = time();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        $resetAt = (int) $response->getHeaderLine('X-RateLimit-Reset');
        self::assertGreaterThanOrEqual($before, $resetAt);
        self::assertLessThanOrEqual($after + 30, $resetAt);
    }

    public function testMiddlewareUsesIntegerOrganizationIdAndOAuthClientHeaderInAuditMetadata(): void
    {
        $auditRepository = new RateLimitAuditRepository();
        $app = $this->createProtectedApp(new RateLimiter(new InMemoryRateLimitStore()), new AuditLogService($auditRepository));

        $requestFactory = new ServerRequestFactory();
        $first = $app->handle($requestFactory
            ->createServerRequest('POST', '/limited', ['REMOTE_ADDR' => '203.0.113.10'])
            ->withQueryParams(['organization_id' => 99])
            ->withHeader('X-OAuth-Client-Id', 'oauth-client-a')
            ->withHeader('X-Request-Id', 'rate-limit-int-org-1'));
        $second = $app->handle($requestFactory
            ->createServerRequest('POST', '/limited', ['REMOTE_ADDR' => '203.0.113.10'])
            ->withQueryParams(['organization_id' => 99])
            ->withHeader('X-OAuth-Client-Id', 'oauth-client-a')
            ->withHeader('X-Request-Id', 'rate-limit-int-org-2'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame(99, $auditRepository->entries[0]->organizationId ?? null);
        self::assertSame('oauth-client-a', $auditRepository->entries[0]->metadata['client_id'] ?? null);
    }

    public function testMiddlewareAllowsRequestsWithoutOrganizationIdOrClientHeader(): void
    {
        $auditRepository = new RateLimitAuditRepository();
        $app = $this->createProtectedApp(new RateLimiter(new InMemoryRateLimitStore()), new AuditLogService($auditRepository));

        $response = $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/limited', ['REMOTE_ADDR' => '   '])
            ->withHeader('X-Request-Id', 'rate-limit-no-org'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame([], $auditRepository->entries);
    }

    private function createProtectedApp(RateLimiter $limiter, AuditLogService $audit, mixed $clock = null): App
    {
        $app = new App(new ResponseFactory());
        $responseFactory = $app->getResponseFactory();
        $app->post('/limited', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->add(new RateLimitMiddleware(
            $responseFactory,
            $limiter,
            new RateLimitPolicy(limit: 1, windowSeconds: 30),
            $audit,
            $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-08T00:00:00Z'),
        ));
        $app->add(new ApiEnvelopeMiddleware($responseFactory));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function handle(App $app, string $requestId): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/limited?organization_id=99', ['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('X-Request-Id', $requestId));
    }
}

final class RateLimitAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
