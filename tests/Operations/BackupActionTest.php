<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Http\Action\Operations\BackupAction;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\Backup\BackupService;

final class BackupActionTest extends TestCase
{
    public function testCreatesListsAndGetsBackupJobs(): void
    {
        [$action] = $this->action();
        $create = $action->create($this->request('POST', '/backups'), new Response());
        $created = $this->json($create);
        $list = $action->list($this->request('GET', '/backups?limit=1&offset=0'), new Response());
        $get = $action->get($this->request('GET', '/backups/backup_action_id'), new Response(), ['job_id' => 'backup_action_id']);

        self::assertSame(202, $create->getStatusCode());
        self::assertSame('backup_action_id', $created['job_id']);
        self::assertSame('backup_action_id', $this->json($list)['items'][0]['job_id']);
        self::assertSame('backup_action_id', $this->json($get)['job_id']);
    }

    public function testReturnsValidationAndNotFoundErrors(): void
    {
        [$action] = $this->action();
        $badList = $action->list(
            $this->request('GET', '/backups')->withQueryParams(['limit' => 'bad', 'offset' => 0]),
            new Response(),
        );
        $badRange = $action->list(
            $this->request('GET', '/backups')->withQueryParams(['limit' => 0, 'offset' => 0]),
            new Response(),
        );
        $missing = $action->get($this->request('GET', '/backups/missing'), new Response(), ['job_id' => 'missing']);

        self::assertSame(422, $badList->getStatusCode());
        self::assertSame('invalid_request', $this->json($badList)['code']);
        self::assertSame(422, $badRange->getStatusCode());
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('backup_job_not_found', $this->json($missing)['code']);
    }

    public function testQueuesRestoreAndMapsEveryServiceConflict(): void
    {
        [$action, $jobs] = $this->action();
        $jobs->save($this->backup('completed'));
        $success = $action->restore(
            $this->request('POST', '/backups/backup_source_id/restore')->withParsedBody([
                'confirmation' => 'backup_source_id',
                'reason' => 'Scheduled staging restore drill',
            ]),
            new Response(),
            ['job_id' => 'backup_source_id'],
        );
        self::assertSame(202, $success->getStatusCode());
        self::assertSame('restore_action_id', $this->json($success)['job_id']);

        $duplicate = $action->restore(
            $this->request('POST', '/restore')->withParsedBody([
                'confirmation' => 'backup_source_id',
                'reason' => 'Second staging restore drill',
            ]),
            new Response(),
            ['job_id' => 'backup_source_id'],
        );
        self::assertSame(409, $duplicate->getStatusCode());
        self::assertSame('restore_already_queued', $this->json($duplicate)['code']);

        $invalid = $action->restore(
            $this->request('POST', '/restore')->withParsedBody(['confirmation' => 'wrong', 'reason' => 'short']),
            new Response(),
            ['job_id' => 'backup_source_id'],
        );
        self::assertSame(422, $invalid->getStatusCode());

        [$notFound] = $this->action();
        $missing = $notFound->restore(
            $this->request('POST', '/restore')->withParsedBody(['confirmation' => 'missing', 'reason' => 'Valid restore reason']),
            new Response(),
            ['job_id' => 'missing'],
        );
        self::assertSame(404, $missing->getStatusCode());

        [$notReady, $notReadyJobs] = $this->action();
        $notReadyJobs->save($this->backup('queued'));
        $conflict = $notReady->restore(
            $this->request('POST', '/restore')->withParsedBody(['confirmation' => 'backup_source_id', 'reason' => 'Valid restore reason']),
            new Response(),
            ['job_id' => 'backup_source_id'],
        );
        self::assertSame('backup_not_restorable', $this->json($conflict)['code']);
    }

    public function testMapsForbiddenEnvironmentAndUnauthenticatedCreateValidation(): void
    {
        [$forbidden, $jobs] = $this->action('production');
        $jobs->save($this->backup('completed'));
        $response = $forbidden->restore(
            $this->request('POST', '/restore')->withParsedBody([
                'confirmation' => 'backup_source_id',
                'reason' => 'Valid production restore reason',
            ]),
            new Response(),
            ['job_id' => 'backup_source_id'],
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('restore_environment_forbidden', $this->json($response)['code']);

        [$action] = $this->action();
        $unauthenticated = (new ServerRequestFactory())->createServerRequest('POST', '/backups');
        self::assertSame(422, $action->create($unauthenticated, new Response())->getStatusCode());
    }

    public function testDoesNotMisclassifyInfrastructureFailuresAsBusinessErrors(): void
    {
        $repository = new class implements BackupJobRepositoryInterface {
            public function save(BackupJob $job): BackupJob
            {
                return $job;
            }

            public function find(string $jobId): ?BackupJob
            {
                throw new RuntimeException('backup repository offline');
            }

            public function list(?string $jobType, int $limit, int $offset): array
            {
                return ['items' => [], 'total' => 0];
            }

            public function claimNext(string $jobType, DateTimeImmutable $startedAt): ?BackupJob
            {
                return null;
            }

            public function latestCompleted(string $jobType): ?BackupJob
            {
                return null;
            }
        };
        $service = new BackupService(
            $repository,
            new AuditLogService(new OperationAuditRepository()),
            'staging',
        );
        $action = new BackupAction($service);

        foreach (['get', 'restore'] as $operation) {
            try {
                if ($operation === 'get') {
                    $action->get($this->request('GET', '/backups/backup_source_id'), new Response(), ['job_id' => 'backup_source_id']);
                } else {
                    $action->restore(
                        $this->request('POST', '/backups/backup_source_id/restore')->withParsedBody([
                            'confirmation' => 'backup_source_id',
                            'reason' => 'Scheduled restore drill',
                        ]),
                        new Response(),
                        ['job_id' => 'backup_source_id'],
                    );
                }
                self::fail('Expected infrastructure failure to propagate.');
            } catch (RuntimeException $exception) {
                self::assertSame('backup repository offline', $exception->getMessage());
            }
        }
    }

    /** @return array{BackupAction,InMemoryBackupJobRepository} */
    private function action(string $environment = 'staging'): array
    {
        $jobs = new InMemoryBackupJobRepository();
        $audit = new OperationAuditRepository();
        $service = new BackupService(
            $jobs,
            new AuditLogService($audit),
            $environment,
            ['staging'],
            static fn (string $prefix): string => $prefix . '_action_id',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
        );

        return [new BackupAction($service), $jobs];
    }

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withHeader('X-Request-Id', 'req-action')
            ->withAttribute(RequestUserContext::ATTRIBUTE, new RequestUserContext(
                new AuthenticatedUser(7, 'admin@example.test', true),
            ));
    }

    private function backup(string $status): BackupJob
    {
        return new BackupJob(
            jobId: 'backup_source_id',
            jobType: 'backup',
            sourceBackupId: null,
            status: $status,
            requestedByUserId: 7,
            requestId: 'req-source',
            environment: 'staging',
            reason: null,
            manifestObjectKey: $status === 'completed' ? 'backups/manifest.json' : null,
            manifestSha256: $status === 'completed' ? str_repeat('b', 64) : null,
            mysqlObjectKey: null,
            mysqlSha256: null,
            configObjectKey: null,
            evidenceObjectKey: null,
            objectCount: 0,
            byteCount: 0,
            errorMessage: null,
            createdAt: new DateTimeImmutable('2026-07-10T09:00:00Z'),
            startedAt: null,
            completedAt: $status === 'completed' ? new DateTimeImmutable('2026-07-10T09:01:00Z') : null,
        );
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
