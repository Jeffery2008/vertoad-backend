<?php

declare(strict_types=1);

namespace VertoAD\Tests\AuditLogs;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepository;

final class AuditLogQueryRepositoryTest extends TestCase
{
    public function testSearchFiltersSortsAndPaginatesAuditLogs(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createAuditLogsTable($connection);
        $repository = new AuditLogRepository($connection);

        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 10, 7, 3, '2026-06-15 10:00:00');
        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 11, 7, 3, '2026-06-15 10:00:00');
        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 13, 7, 4, '2026-06-15 10:30:00');
        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'recharge_key', 11, 7, 3, '2026-06-15 09:00:00');
        $this->insertAuditLog($connection, $repository, 'support.ticket.updated', 'support_ticket', 20, 8, 4, '2026-06-15 11:00:00');
        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 12, 7, 3, '2026-06-14 23:59:59');

        $result = $repository->search([
            'action' => 'billing.ledger.adjusted',
            'organization_id' => 3,
            'actor_user_id' => 7,
            'subject_type' => 'ledger_entry',
            'created_from' => '2026-06-15 00:00:00',
            'created_to' => '2026-06-15 23:59:59',
            'limit' => 1,
            'offset' => 1,
        ]);

        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['limit']);
        self::assertSame(1, $result['offset']);
        self::assertFalse($result['has_more']);
        self::assertCount(1, $result['items']);
        self::assertSame(10, $result['items'][0]->subjectId);
        self::assertSame(['correlation_id' => 'req-10', 'nested' => ['token' => 'secret-token']], $result['items'][0]->metadata);
        self::assertSame('127.0.0.1', $result['items'][0]->ipAddress);
    }

    public function testSearchFiltersBySubjectId(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createAuditLogsTable($connection);
        $repository = new AuditLogRepository($connection);

        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 10, 7, 3, '2026-06-15 10:00:00');
        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 11, 7, 3, '2026-06-15 11:00:00');

        $result = $repository->search([
            'subject_id' => 11,
            'limit' => 50,
            'offset' => 0,
        ]);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame(11, $result['items'][0]->subjectId);
    }

    public function testSearchReportsHasMoreAndHydratesMissingRawContextAsNull(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        self::createAuditLogsTable($connection);
        $repository = new AuditLogRepository($connection);

        $repository->append(new AuditLogEntry(
            action: 'admin.login',
            subjectType: 'user',
            subjectId: null,
            actorUserId: null,
            organizationId: null,
            packedIpAddress: null,
            userAgent: null,
            metadata: null,
        ));
        $connection->update('audit_logs', ['created_at' => '2026-06-15 12:00:00'], ['id' => (int) $connection->lastInsertId()]);
        $this->insertAuditLog($connection, $repository, 'billing.ledger.adjusted', 'ledger_entry', 11, 7, 3, '2026-06-15 11:00:00');

        $result = $repository->search(['limit' => 1, 'offset' => 0]);

        self::assertSame(2, $result['total']);
        self::assertTrue($result['has_more']);
        self::assertNull($result['items'][0]->organizationId);
        self::assertNull($result['items'][0]->actorUserId);
        self::assertNull($result['items'][0]->subjectId);
        self::assertNull($result['items'][0]->ipAddress);
        self::assertNull($result['items'][0]->userAgent);
        self::assertNull($result['items'][0]->metadata);
    }

    public static function createAuditLogsTable(Connection $connection): void
    {
        $connection->executeStatement(
            <<<'SQL'
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NULL,
    actor_user_id INTEGER NULL,
    action VARCHAR(160) NOT NULL,
    subject_type VARCHAR(120) NOT NULL,
    subject_id INTEGER NULL,
    ip_address BLOB NULL,
    user_agent VARCHAR(512) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
        );
    }

    private function insertAuditLog(
        Connection $connection,
        AuditLogRepository $repository,
        string $action,
        string $subjectType,
        int $subjectId,
        int $actorUserId,
        int $organizationId,
        string $createdAt,
    ): void {
        $repository->append(new AuditLogEntry(
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actorUserId: $actorUserId,
            organizationId: $organizationId,
            packedIpAddress: inet_pton('127.0.0.1') ?: null,
            userAgent: 'PHPUnit',
            metadata: ['correlation_id' => 'req-' . $subjectId, 'nested' => ['token' => 'secret-token']],
        ));

        $connection->update(
            'audit_logs',
            ['created_at' => $createdAt],
            ['id' => (int) $connection->lastInsertId()],
        );
    }
}
