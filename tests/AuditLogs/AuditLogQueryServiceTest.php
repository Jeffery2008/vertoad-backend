<?php

declare(strict_types=1);

namespace VertoAD\Tests\AuditLogs;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Audit\AuditLogRecord;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\AuditLogQueryRepositoryInterface;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class AuditLogQueryServiceTest extends TestCase
{
    public function testListRedactsRawContextForNonSuperAdmins(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $result = $service->search(
            filters: ['limit' => 50, 'offset' => 0],
            context: new RequestUserContext(new AuthenticatedUser(7, 'ops@example.com', false), 10),
        );

        self::assertSame(1, $result['page']['total']);
        self::assertSame([
            'id',
            'organization_id',
            'actor_user_id',
            'action',
            'subject_type',
            'subject_id',
            'ip_address',
            'user_agent',
            'request_id',
            'metadata',
            'context_redacted',
            'created_at',
        ], array_keys($result['items'][0]));
        self::assertNull($result['items'][0]['ip_address']);
        self::assertNull($result['items'][0]['user_agent']);
        self::assertNull($result['items'][0]['metadata']);
        self::assertTrue($result['items'][0]['context_redacted']);
    }

    public function testListIncludesRawContextForSuperAdmins(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $result = $service->search(
            filters: ['limit' => 50, 'offset' => 0],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame('203.0.113.10', $result['items'][0]['ip_address']);
        self::assertSame('PHPUnit', $result['items'][0]['user_agent']);
        self::assertSame(['safe' => 'value', 'token' => 'raw-token'], $result['items'][0]['metadata']);
        self::assertFalse($result['items'][0]['context_redacted']);
    }

    public function testListRejectsInvalidPaginationAndFilters(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be a positive integer no greater than 100.');

        $service->search(
            filters: ['limit' => '101', 'offset' => 0],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRejectsInvalidOffset(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('offset must be a non-negative integer.');

        $service->search(
            filters: ['limit' => 50, 'offset' => '-1'],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRejectsInvalidNumericFilter(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actor_user_id must be a positive integer.');

        $service->search(
            filters: ['actor_user_id' => 'abc'],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListNormalizesOrganizationFilter(): void
    {
        $repository = new CapturingAuditLogQueryRepositoryStub();
        $service = new AuditLogService($repository);

        $service->search(
            filters: ['organization_id' => '10'],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame(10, $repository->lastFilters['organization_id'] ?? null);
    }

    public function testListNormalizesRequestIdFilter(): void
    {
        $repository = new CapturingAuditLogQueryRepositoryStub();
        $service = new AuditLogService($repository);

        $service->search(
            filters: ['request_id' => ' req-audit-1 '],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );

        self::assertSame('req-audit-1', $repository->lastFilters['request_id'] ?? null);
    }

    public function testListRejectsInvalidStringFilterType(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('action must be a string.');

        $service->search(
            filters: ['action' => ['billing.ledger.adjusted']],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRejectsZeroNumericFilter(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subject_id must be a positive integer.');

        $service->search(
            filters: ['subject_id' => 0],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRejectsInvalidDateFilterType(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('created_to must be a valid date-time.');

        $service->search(
            filters: ['created_to' => ['2026-06-15T00:00:00Z']],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRejectsInvalidDateFilter(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('created_from must be a valid date-time.');

        $service->search(
            filters: ['created_from' => 'not-a-date'],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRejectsInvertedDateRange(): void
    {
        $service = new AuditLogService(new AuditLogQueryRepositoryStub());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('created_from must be before or equal to created_to.');

        $service->search(
            filters: ['created_from' => '2026-06-16T00:00:00Z', 'created_to' => '2026-06-15T00:00:00Z'],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }

    public function testListRequiresQueryableRepository(): void
    {
        $service = new AuditLogService(new class implements AuditLogRepositoryInterface {
            public function append(AuditLogEntry $entry): void
            {
            }
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit log repository does not support querying.');

        $service->search(
            filters: [],
            context: new RequestUserContext(new AuthenticatedUser(1, 'root@example.com', true), null),
        );
    }
}

final class AuditLogQueryRepositoryStub implements AuditLogRepositoryInterface, AuditLogQueryRepositoryInterface
{
    public function append(AuditLogEntry $entry): void
    {
    }

    public function search(array $filters): array
    {
        TestCase::assertSame(50, $filters['limit']);
        TestCase::assertSame(0, $filters['offset']);

        return [
            'items' => [
                new AuditLogRecord(
                    id: 99,
                    organizationId: 10,
                    actorUserId: 7,
                    action: 'billing.recharge_key.revealed',
                    subjectType: 'recharge_key',
                    subjectId: 123,
                    ipAddress: '203.0.113.10',
                    userAgent: 'PHPUnit',
                    requestId: 'req-audit-stub',
                    metadata: ['safe' => 'value', 'token' => 'raw-token'],
                    createdAt: '2026-06-15T10:00:00Z',
                ),
            ],
            'limit' => 50,
            'offset' => 0,
            'total' => 1,
            'has_more' => false,
        ];
    }
}

final class CapturingAuditLogQueryRepositoryStub implements AuditLogRepositoryInterface, AuditLogQueryRepositoryInterface
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
            'items' => [],
            'limit' => $filters['limit'],
            'offset' => $filters['offset'],
            'total' => 0,
            'has_more' => false,
        ];
    }
}
