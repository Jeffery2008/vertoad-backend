<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Http\Action\Operations\BackupAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RequestIdMiddleware;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\Backup\BackupService;

final class BackupRouteIntegrationTest extends TestCase
{
    public function testBackupRoutesReturnEnvelopedJobsWithStableRequestIds(): void
    {
        $jobs = new InMemoryBackupJobRepository();
        $service = new BackupService(
            $jobs,
            new AuditLogService(new OperationAuditRepository()),
            'staging',
            ['staging'],
            static fn (string $prefix): string => $prefix . '_route_id',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
        );
        $app = $this->app(new BackupAction($service));

        $created = $this->handle($app, 'POST', '/api/v1/operations/backups', requestId: 'req-backup-create');
        self::assertSame(202, $created['status']);
        self::assertSame('backup_route_id', $created['body']['data']['job_id']);
        self::assertSame('req-backup-create', $created['body']['request_id']);
        self::assertNull($created['body']['error']);

        $queued = $jobs->find('backup_route_id');
        self::assertInstanceOf(BackupJob::class, $queued);
        $jobs->save(new BackupJob(
            jobId: $queued->jobId,
            jobType: $queued->jobType,
            sourceBackupId: null,
            status: 'completed',
            requestedByUserId: $queued->requestedByUserId,
            requestId: $queued->requestId,
            environment: $queued->environment,
            reason: null,
            manifestObjectKey: 'backups/backup_route_id/manifest.json',
            manifestSha256: str_repeat('b', 64),
            mysqlObjectKey: 'backups/backup_route_id/mysql.sql',
            mysqlSha256: str_repeat('a', 64),
            configObjectKey: 'backups/backup_route_id/configuration.json',
            evidenceObjectKey: null,
            objectCount: 2,
            byteCount: 2048,
            errorMessage: null,
            createdAt: $queued->createdAt,
            startedAt: new DateTimeImmutable('2026-07-10T10:01:00Z'),
            completedAt: new DateTimeImmutable('2026-07-10T10:02:00Z'),
        ));

        $list = $this->handle($app, 'GET', '/api/v1/operations/backups?job_type=backup&limit=10&offset=0', requestId: 'req-backup-list');
        $get = $this->handle($app, 'GET', '/api/v1/operations/backups/backup_route_id', requestId: 'req-backup-get');
        $restore = $this->handle($app, 'POST', '/api/v1/operations/backups/backup_route_id/restore', [
            'confirmation' => 'backup_route_id',
            'reason' => 'Scheduled staging restore drill',
        ], 'req-backup-restore');

        self::assertSame(200, $list['status']);
        self::assertSame('backup_route_id', $list['body']['data']['items'][0]['job_id']);
        self::assertSame('req-backup-list', $list['body']['request_id']);
        self::assertSame(200, $get['status']);
        self::assertSame('completed', $get['body']['data']['status']);
        self::assertSame('req-backup-get', $get['body']['request_id']);
        self::assertSame(202, $restore['status']);
        self::assertSame('restore_route_id', $restore['body']['data']['job_id']);
        self::assertSame('backup_route_id', $restore['body']['data']['source_backup_id']);
        self::assertSame('req-backup-restore', $restore['body']['request_id']);
    }

    private function app(BackupAction $action): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            BackupAction::class => static fn (): BackupAction => $action,
        ])->build();
        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/operations/backups', [BackupAction::class, 'list']);
        $app->post('/api/v1/operations/backups', [BackupAction::class, 'create']);
        $app->get('/api/v1/operations/backups/{job_id}', [BackupAction::class, 'get']);
        $app->post('/api/v1/operations/backups/{job_id}/restore', [BackupAction::class, 'restore']);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);
        $app->add(new RequestIdMiddleware());

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int,body:array<string,mixed>}
     */
    private function handle(
        App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        string $requestId = 'req-backup-route',
    ): array {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withHeader('X-Request-Id', $requestId)
            ->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
                new AuthenticatedUser(7, 'ops@example.test', true),
            ));
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }
        $response = $app->handle($request);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }
}
