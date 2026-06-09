<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\AuditLogService;

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
