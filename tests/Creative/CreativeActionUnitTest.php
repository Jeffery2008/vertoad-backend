<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Creative\CreateCreativeDesignAction;
use VertoAD\Http\Action\Creative\CreateCreativeDesignVersionAction;
use VertoAD\Http\Action\Creative\CreateCreativeTemplateAction;
use VertoAD\Http\Action\Creative\ListCreativeDesignVersionsAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Creative\DatabaseCreativeDesignRepository;
use VertoAD\Repository\Creative\DatabaseCreativeTemplateRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Creative\CreativeDesignService;

final class CreativeActionUnitTest extends TestCase
{
    public function testTemplateActionOwnsAuthenticationPayloadAndOrganizationScopeErrors(): void
    {
        $action = new CreateCreativeTemplateAction($this->service($this->connection()));
        $responseFactory = new ResponseFactory();

        $unauthenticated = $action($this->request('POST', '/api/v1/creative/templates', [
            'scope' => 'organization',
            'organization_id' => 101,
        ]), $responseFactory->createResponse());
        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame('authentication_required', $this->payload($unauthenticated)['code'] ?? null);

        $invalidPayload = $action(
            $this->request('POST', '/api/v1/creative/templates', context: $this->memberContext()),
            $responseFactory->createResponse(),
        );
        self::assertSame(422, $invalidPayload->getStatusCode());
        self::assertSame('invalid_request', $this->payload($invalidPayload)['code'] ?? null);

        $missingScope = $action($this->request('POST', '/api/v1/creative/templates', [
            'scope' => 'organization',
            'name' => 'Missing org',
        ], new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), null)), $responseFactory->createResponse());
        self::assertSame(400, $missingScope->getStatusCode());
        self::assertSame('organization_scope_required', $this->payload($missingScope)['code'] ?? null);
    }

    public function testDesignActionOwnsAuthenticationPayloadAndScopeErrors(): void
    {
        $action = new CreateCreativeDesignAction($this->service($this->connection()));
        $responseFactory = new ResponseFactory();

        $unauthenticated = $action($this->request('POST', '/api/v1/creative/designs', [
            'organization_id' => 101,
        ]), $responseFactory->createResponse());
        self::assertSame(401, $unauthenticated->getStatusCode());
        self::assertSame('authentication_required', $this->payload($unauthenticated)['code'] ?? null);

        $invalidPayload = $action(
            $this->request('POST', '/api/v1/creative/designs', context: $this->memberContext()),
            $responseFactory->createResponse(),
        );
        self::assertSame(422, $invalidPayload->getStatusCode());
        self::assertSame('invalid_request', $this->payload($invalidPayload)['code'] ?? null);

        $mismatchedScope = $action($this->request('POST', '/api/v1/creative/designs', [
            'organization_id' => 202,
            'name' => 'Wrong org',
        ], $this->memberContext()), $responseFactory->createResponse());
        self::assertSame(403, $mismatchedScope->getStatusCode());
        self::assertSame('organization_scope_mismatch', $this->payload($mismatchedScope)['code'] ?? null);
    }

    public function testVersionActionsOwnAuthenticationScopeValidationAndNotFoundErrors(): void
    {
        $service = $this->service($this->connection());
        $create = new CreateCreativeDesignVersionAction($service);
        $list = new ListCreativeDesignVersionsAction($service);
        $responseFactory = new ResponseFactory();

        $unauthenticatedCreate = $create($this->request('POST', '/api/v1/creative/designs/dsn_missing/versions', [
            'organization_id' => 101,
            'fabric_json' => ['version' => '5.3.0'],
        ]), $responseFactory->createResponse(), ['design_id' => 'dsn_missing']);
        self::assertSame(401, $unauthenticatedCreate->getStatusCode());
        self::assertSame('authentication_required', $this->payload($unauthenticatedCreate)['code'] ?? null);

        $missingScope = $create($this->request('POST', '/api/v1/creative/designs/dsn_missing/versions', [
            'fabric_json' => ['version' => '5.3.0'],
        ], $this->memberContext()), $responseFactory->createResponse(), ['design_id' => 'dsn_missing']);
        self::assertSame(400, $missingScope->getStatusCode());
        self::assertSame('organization_scope_required', $this->payload($missingScope)['code'] ?? null);

        $mismatchedScope = $create($this->request('POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=202', [
            'organization_id' => 202,
            'fabric_json' => ['version' => '5.3.0'],
        ], $this->memberContext()), $responseFactory->createResponse(), ['design_id' => 'dsn_missing']);
        self::assertSame(403, $mismatchedScope->getStatusCode());
        self::assertSame('organization_scope_mismatch', $this->payload($mismatchedScope)['code'] ?? null);

        $invalidPayload = $create(
            $this->request('POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', context: $this->memberContext()),
            $responseFactory->createResponse(),
            ['design_id' => 'dsn_missing'],
        );
        self::assertSame(422, $invalidPayload->getStatusCode());
        self::assertSame('invalid_request', $this->payload($invalidPayload)['code'] ?? null);

        $invalidDesignId = $create($this->request('POST', '/api/v1/creative/designs/%20/versions?organization_id=101', [
            'fabric_json' => ['version' => '5.3.0'],
        ], $this->memberContext()), $responseFactory->createResponse(), ['design_id' => ' ']);
        self::assertSame(422, $invalidDesignId->getStatusCode());
        self::assertSame('invalid_request', $this->payload($invalidDesignId)['code'] ?? null);

        $missingDesign = $create($this->request('POST', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101', [
            'fabric_json' => ['version' => '5.3.0'],
        ], $this->memberContext()), $responseFactory->createResponse(), ['design_id' => 'dsn_missing']);
        self::assertSame(404, $missingDesign->getStatusCode());
        self::assertSame('not_found', $this->payload($missingDesign)['code'] ?? null);

        $unauthenticatedList = $list(
            $this->request('GET', '/api/v1/creative/designs/dsn_missing/versions?organization_id=101'),
            $responseFactory->createResponse(),
            ['design_id' => 'dsn_missing'],
        );
        self::assertSame(401, $unauthenticatedList->getStatusCode());
        self::assertSame('authentication_required', $this->payload($unauthenticatedList)['code'] ?? null);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function request(string $method, string $uri, ?array $payload = null, ?RequestUserContext $context = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($context !== null) {
            $request = $request->withAttribute(RequestUserContext::ATTRIBUTE, $context);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function payload(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function memberContext(): RequestUserContext
    {
        return new RequestUserContext(new AuthenticatedUser(7, 'creative@example.com', false), 101);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CreativeSchema::create($connection);

        return $connection;
    }

    private function service(Connection $connection): CreativeDesignService
    {
        return new CreativeDesignService(
            new DatabaseCreativeTemplateRepository($connection),
            new DatabaseCreativeDesignRepository($connection),
            new AuditLogService(new CreativeActionUnitAuditRepository()),
            static fn (string $prefix): string => $prefix . '_' . bin2hex(random_bytes(4)),
        );
    }
}

final class CreativeActionUnitAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
