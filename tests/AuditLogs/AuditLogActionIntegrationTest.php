<?php

declare(strict_types=1);

namespace VertoAD\Tests\AuditLogs;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Audit\AuditLogRecord;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\AuditLogs\ListAuditLogsAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Repository\AuditLogQueryRepositoryInterface;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class AuditLogActionIntegrationTest extends TestCase
{
    public function testListAuditLogsReturnsEnvelopeWithItemsAndPageMetadata(): void
    {
        $repository = new AuditLogActionRepositoryStub();
        $app = $this->createApp($repository);

        $response = $this->handle(
            $app,
            '/api/v1/audit-logs?limit=1&offset=0&action=billing.recharge_key.revealed&actor_user_id=7'
                . '&organization_id=10&subject_type=recharge_key&subject_id=123&created_from=2026-06-15T00:00:00Z&created_to=2026-06-15T23:59:59Z',
            new RequestUserContext(new AuthenticatedUser(7, 'ops@example.com', false), 10),
        );

        self::assertSame(200, $response['status']);
        self::assertNull($response['body']['error']);
        self::assertSame('billing.recharge_key.revealed', $response['body']['data']['items'][0]['action']);
        self::assertNull($response['body']['data']['items'][0]['metadata']);
        self::assertTrue($response['body']['data']['items'][0]['context_redacted']);
        self::assertSame([
            'limit' => 1,
            'offset' => 0,
            'total' => 1,
            'has_more' => false,
        ], $response['body']['data']['page']);
        foreach (
            [
                'limit' => 1,
                'offset' => 0,
                'action' => 'billing.recharge_key.revealed',
                'organization_id' => 10,
                'subject_type' => 'recharge_key',
                'actor_user_id' => 7,
                'subject_id' => 123,
                'created_from' => '2026-06-15 00:00:00',
                'created_to' => '2026-06-15 23:59:59',
            ] as $filter => $expectedValue
        ) {
            self::assertSame($expectedValue, $repository->lastFilters[$filter] ?? null, $filter);
        }
    }

    public function testListAuditLogsIncludesMetadataForSuperAdmins(): void
    {
        $app = $this->createApp(new AuditLogActionRepositoryStub());

        $response = $this->handle(
            $app,
            '/api/v1/audit-logs',
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame(200, $response['status']);
        self::assertSame(['secret' => 'raw-value'], $response['body']['data']['items'][0]['metadata']);
        self::assertFalse($response['body']['data']['items'][0]['context_redacted']);
    }

    public function testListAuditLogsReturnsEnvelopeErrorForInvalidPagination(): void
    {
        $app = $this->createApp(new AuditLogActionRepositoryStub());

        $response = $this->handle(
            $app,
            '/api/v1/audit-logs?limit=0',
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame(422, $response['status']);
        self::assertSame('invalid_request', $response['body']['error']['code']);
    }

    private function createApp(AuditLogRepositoryInterface $repository): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            AuditLogRepositoryInterface::class => static fn (): AuditLogRepositoryInterface => $repository,
            AuditLogService::class => static fn (): AuditLogService => new AuditLogService($repository),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/audit-logs', ListAuditLogsAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private function handle(App $app, string $uri, RequestUserContext $context): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withAttribute(RequestUserContext::ATTRIBUTE, $context);

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['status' => $response->getStatusCode(), 'body' => $decoded];
    }
}

final class AuditLogActionRepositoryStub implements AuditLogRepositoryInterface, AuditLogQueryRepositoryInterface
{
    /** @var array<string, mixed> */
    public array $lastFilters = [];

    public function append(AuditLogEntry $entry): void
    {
    }

    public function search(array $filters): array
    {
        $this->lastFilters = $filters;

        return [
            'items' => [
                new AuditLogRecord(
                    id: 101,
                    organizationId: 9,
                    actorUserId: 7,
                    action: 'billing.recharge_key.revealed',
                    subjectType: 'recharge_key',
                    subjectId: 123,
                    ipAddress: '203.0.113.55',
                    userAgent: 'PHPUnit',
                    metadata: ['secret' => 'raw-value'],
                    createdAt: '2026-06-15T10:00:00Z',
                ),
            ],
            'limit' => $filters['limit'],
            'offset' => $filters['offset'],
            'total' => 1,
            'has_more' => false,
        ];
    }
}
