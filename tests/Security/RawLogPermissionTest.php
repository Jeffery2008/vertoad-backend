<?php

declare(strict_types=1);

namespace VertoAD\Tests\Security;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class RawLogPermissionTest extends TestCase
{
    public function testRawOperationErrorContextIsDeniedToNonSuperAdminsByDefault(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryOperationErrorLogRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationErrorCaptureService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new RawLogAuditRepository()));
        $entry = $service->captureApiError(
            requestId: 'req-denied-raw',
            severity: 'error',
            message: 'Sensitive context must stay protected',
            context: ['api_key' => 'raw-api-key'],
            occurredAt: new DateTimeImmutable('2026-06-08T12:10:00Z'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Raw operation error context requires super administrator access.');

        $service->rawContextFor(
            (string) $this->value($entry, 'error_id'),
            new RequestUserContext(new AuthenticatedUser(2, 'ops@example.com', false), 10),
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

final class RawLogAuditRepository implements AuditLogRepositoryInterface
{
    public function append(AuditLogEntry $entry): void
    {
    }
}
