<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Operations\LookupOperationIpGeoAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\RealTimeGeoLookupInterface;

final class LookupOperationIpGeoActionTest extends TestCase
{
    public function testInvalidBodyReturnsUnprocessableEntity(): void
    {
        $action = new LookupOperationIpGeoAction($this->lookup());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/operations/ip-geo/lookup');

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $body['code']);
        self::assertSame('ip_address must be a valid IP address.', $body['message']);
    }

    public function testMissingIpAddressReturnsUnprocessableEntity(): void
    {
        $action = new LookupOperationIpGeoAction($this->lookup());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/operations/ip-geo/lookup')
            ->withParsedBody([]);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $body['code']);
        self::assertSame('ip_address must be a valid IP address.', $body['message']);
    }

    public function testInvalidIpAddressReturnsUnprocessableEntityBeforeLookup(): void
    {
        $lookup = $this->lookup();
        $action = new LookupOperationIpGeoAction($lookup);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/operations/ip-geo/lookup')
            ->withParsedBody(['ip_address' => 'not-an-ip']);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $body['code']);
        self::assertSame('ip_address must be a valid IP address.', $body['message']);
        self::assertSame([], $lookup->calls);
    }

    public function testLookupInvalidArgumentReturnsUnprocessableEntityMessage(): void
    {
        $action = new LookupOperationIpGeoAction($this->lookup(exception: new \InvalidArgumentException('provider rejected ip_address.')));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/operations/ip-geo/lookup')
            ->withParsedBody(['ip_address' => '203.0.113.30']);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_request', $body['code']);
        self::assertSame('provider rejected ip_address.', $body['message']);
    }

    public function testSuccessfulLookupPassesRequestIdAndWritesAuditEntry(): void
    {
        RequestIdContext::clear();
        $auditRepository = new OperationAuditRepository();
        $lookup = $this->lookup([
            'ip_address' => '203.0.113.31',
            'canonical_geo_code' => null,
            'country_code' => null,
            'region_code' => null,
            'region' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'provider_id' => null,
            'source' => 'queued',
            'queried_at' => '2026-06-08T13:30:00Z',
            'persisted_to_canonical_store' => false,
        ]);
        $action = new LookupOperationIpGeoAction($lookup, new AuditLogService($auditRepository));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/operations/ip-geo/lookup')
            ->withHeader('X-Request-Id', 'req-action-geo')
            ->withParsedBody(['ip_address' => ' 203.0.113.31 '])
            ->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(7, 'ops@example.com', false), 99),
            );

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('203.0.113.31', $body['ip_address']);
        self::assertSame([['203.0.113.31', 'req-action-geo']], $lookup->calls);
        self::assertCount(1, $auditRepository->entries);
        $audit = $auditRepository->entries[0];
        self::assertSame('operations.ip_geo.lookup', $audit->action);
        self::assertSame('ip_geo_lookup', $audit->subjectType);
        self::assertSame(7, $audit->actorUserId);
        self::assertSame(99, $audit->organizationId);
        self::assertSame('req-action-geo', $audit->requestId);
        self::assertSame('203.0.113.31', $audit->metadata['ip_address']);
        self::assertSame('queued', $audit->metadata['source']);
        self::assertSame('/api/v1/operations/ip-geo/lookup', $audit->metadata['endpoint']);
        self::assertSame('POST', $audit->metadata['method']);
    }

    private function lookup(?array $result = null, ?\InvalidArgumentException $exception = null): RecordingRealTimeGeoLookup
    {
        return new RecordingRealTimeGeoLookup($result ?? [
            'ip_address' => '203.0.113.1',
            'source' => 'test',
        ], $exception);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class RecordingRealTimeGeoLookup implements RealTimeGeoLookupInterface
{
    /** @var list<array{0:string,1:?string}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        private array $result,
        private ?\InvalidArgumentException $exception = null,
    ) {
    }

    public function lookup(string $ipAddress, ?string $requestId = null): array
    {
        $this->calls[] = [$ipAddress, $requestId];
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return ['ip_address' => $ipAddress, ...$this->result];
    }
}
