<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Operations\InMemoryOperationErrorLogRepository;
use VertoAD\Repository\Operations\InMemoryOperationSystemLogRepository;
use VertoAD\Repository\Operations\OperationSystemLogRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\OperationErrorCaptureService;

final class OperationErrorCaptureServiceTest extends TestCase
{
    public function testCapturesApiErrorMetadataWithRedactedAndRawContextSeparated(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryOperationErrorLogRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationErrorCaptureService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $auditRepository = new OperationAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));

        $entry = $service->captureApiError(
            requestId: 'req-ops-1',
            severity: 'critical',
            message: 'PDOException: password leaked in DSN',
            context: [
                'route' => 'POST /api/v1/campaigns',
                'authorization' => 'Bearer very-secret-token',
                'password' => 'plain-secret',
                'safe' => 'keep-me',
            ],
            occurredAt: new DateTimeImmutable('2026-06-08T12:00:00Z'),
        );
        $listed = $service->listErrors();

        self::assertNotSame('', (string) $this->value($entry, 'error_id'));
        self::assertSame('req-ops-1', $this->value($entry, 'request_id'));
        self::assertSame('critical', $this->value($entry, 'severity'));
        self::assertSame('PDOException: password leaked in DSN', $this->value($entry, 'message'));
        self::assertSame('keep-me', $this->value($this->value($entry, 'redacted_context'), 'safe'));
        self::assertSame('[REDACTED]', $this->value($this->value($entry, 'redacted_context'), 'authorization'));
        self::assertSame('[REDACTED]', $this->value($this->value($entry, 'redacted_context'), 'password'));
        self::assertNull($this->value($entry, 'raw_context'));
        self::assertCount(1, $listed);
        self::assertNull($this->value($listed[0], 'raw_context'));
    }

    public function testSuperAdminCanViewRawContextAndViewWritesAuditEvent(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryOperationErrorLogRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationErrorCaptureService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $auditRepository = new OperationAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));
        $entry = $service->capturePhpError(
            requestId: 'req-php-1',
            severity: 'warning',
            message: 'Undefined array key',
            context: ['token' => 'raw-token', 'file' => 'src/Foo.php'],
            occurredAt: new DateTimeImmutable('2026-06-08T12:05:00Z'),
        );

        $raw = $service->rawContextFor(
            (string) $this->value($entry, 'error_id'),
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame('raw-token', $this->value($raw, 'token'));
        self::assertSame('operations.error.raw_context.viewed', $auditRepository->entries[0]->action ?? null);
        self::assertSame('operation_error_log', $auditRepository->entries[0]->subjectType ?? null);
        self::assertSame(1, $auditRepository->entries[0]->actorUserId ?? null);
        self::assertSame((string) $this->value($entry, 'error_id'), $auditRepository->entries[0]->metadata['error_id'] ?? null);
        self::assertSame('req-php-1', $auditRepository->entries[0]->metadata['request_id'] ?? null);
    }

    public function testListErrorsCanFilterByRequestId(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryOperationErrorLogRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationErrorCaptureService';
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new OperationAuditRepository()));

        $service->captureApiError(
            requestId: 'req-filter-match',
            severity: 'error',
            message: 'Matched request error',
            context: [],
            occurredAt: new DateTimeImmutable('2026-06-08T12:00:00Z'),
        );
        $service->captureApiError(
            requestId: 'req-filter-other',
            severity: 'error',
            message: 'Other request error',
            context: [],
            occurredAt: new DateTimeImmutable('2026-06-08T12:01:00Z'),
        );

        $listed = $service->listErrors('req-filter-match');

        self::assertCount(1, $listed);
        self::assertSame('req-filter-match', $this->value($listed[0], 'request_id'));
    }

    public function testCaptureWritesDurableSystemLogWithRedactedContext(): void
    {
        $errorRepository = new InMemoryOperationErrorLogRepository();
        $systemLogs = new InMemoryOperationSystemLogRepository();
        $service = new OperationErrorCaptureService(
            $errorRepository,
            new AuditLogService(new OperationAuditRepository()),
            $systemLogs,
        );

        $entry = $service->captureApiError(
            requestId: 'req-system-write',
            severity: 'warning',
            message: 'Track payload normalized',
            context: [
                'path' => '/api/v1/ads/track',
                'method' => 'POST',
                'ip_address' => '198.51.100.88',
                'authorization' => 'Bearer raw-token',
            ],
            occurredAt: new DateTimeImmutable('2026-06-18T04:00:00Z'),
        );
        $logs = $systemLogs->search(['request_id' => 'req-system-write']);

        self::assertCount(1, $logs);
        self::assertSame('syslog_' . sha1((string) $this->value($entry, 'error_id')), $logs[0]->log_id);
        self::assertSame('warning', $logs[0]->level);
        self::assertSame('/api/v1/ads/track', $logs[0]->endpoint);
        self::assertSame('POST', $logs[0]->http_method);
        self::assertSame('198.51.100.88', $logs[0]->ip_address);
        self::assertSame('[REDACTED]', $logs[0]->redacted_context['authorization'] ?? null);
        self::assertSame('Bearer raw-token', $logs[0]->raw_context['authorization'] ?? null);
    }

    public function testCaptureSystemLogIgnoresNonScalarCorrelationContext(): void
    {
        $systemLogs = new InMemoryOperationSystemLogRepository();
        $service = new OperationErrorCaptureService(
            new InMemoryOperationErrorLogRepository(),
            new AuditLogService(new OperationAuditRepository()),
            $systemLogs,
        );

        $service->captureApiError(
            requestId: 'req-system-non-scalar',
            severity: 'warning',
            message: 'Malformed context metadata',
            context: [
                'endpoint' => ['POST', '/api/v1/ads/track'],
                'method' => ['POST'],
                'ip_address' => ['198.51.100.88'],
            ],
            occurredAt: new DateTimeImmutable('2026-06-18T04:05:00Z'),
        );
        $logs = $systemLogs->search(['request_id' => 'req-system-non-scalar']);

        self::assertCount(1, $logs);
        self::assertNull($logs[0]->endpoint);
        self::assertNull($logs[0]->http_method);
        self::assertNull($logs[0]->ip_address);
    }

    public function testCaptureStillReturnsErrorWhenDurableSystemLogWriteFails(): void
    {
        $service = new OperationErrorCaptureService(
            new InMemoryOperationErrorLogRepository(),
            new AuditLogService(new OperationAuditRepository()),
            new class implements OperationSystemLogRepositoryInterface {
                public function append(\VertoAD\Domain\Operations\OperationSystemLog $entry): \VertoAD\Domain\Operations\OperationSystemLog
                {
                    throw new \RuntimeException('system log table unavailable');
                }

                public function find(string $logId): ?\VertoAD\Domain\Operations\OperationSystemLog
                {
                    return null;
                }

                public function search(array $filters = []): array
                {
                    return [];
                }
            },
        );

        $entry = $service->captureApiError(
            requestId: 'req-system-log-failure',
            severity: 'error',
            message: 'Primary error must still be captured',
            context: ['path' => '/api/v1/ads/track'],
            occurredAt: new DateTimeImmutable('2026-06-18T04:10:00Z'),
        );

        self::assertSame('req-system-log-failure', $this->value($entry, 'request_id'));
    }

    public function testRejectsBlankErrorMetadataAndMissingRawContext(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryOperationErrorLogRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationErrorCaptureService';
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new OperationAuditRepository()));

        try {
            $service->captureApiError('', 'error', 'message', [], new DateTimeImmutable('2026-06-08T12:00:00Z'));
            self::fail('Blank request ID must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('requestId is required.', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation error log not found.');

        $service->rawContextFor(
            'missing',
            new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    private function value(mixed $record, string $key): mixed
    {
        if (is_array($record)) {
            return $record[$key] ?? null;
        }

        if (is_object($record)) {
            return $record->{$key} ?? null;
        }

        return null;
    }
}
