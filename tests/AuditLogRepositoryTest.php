<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepository;

final class AuditLogRepositoryTest extends TestCase
{
    public function testAppendPersistsAuditLogWithDeterministicMetadataJson(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
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

        $repository = new AuditLogRepository($connection);

        $repository->append(new AuditLogEntry(
            action: 'admin.config.update',
            subjectType: 'system_config',
            subjectId: 42,
            actorUserId: 7,
            organizationId: 3,
            packedIpAddress: "\x7f\x00\x00\x01",
            userAgent: 'Mozilla/5.0',
            metadata: ['a' => ['first' => 1, 'second' => 2], 'z' => 'last'],
        ));

        $row = $connection->fetchAssociative('SELECT * FROM audit_logs');

        self::assertIsArray($row);
        self::assertSame(3, (int) $row['organization_id']);
        self::assertSame(7, (int) $row['actor_user_id']);
        self::assertSame('admin.config.update', $row['action']);
        self::assertSame('system_config', $row['subject_type']);
        self::assertSame(42, (int) $row['subject_id']);
        self::assertSame("\x7f\x00\x00\x01", $row['ip_address']);
        self::assertSame('Mozilla/5.0', $row['user_agent']);
        self::assertSame('{"a":{"first":1,"second":2},"z":"last"}', $row['metadata_json']);
    }

    public function testAppendPersistsNullMetadataWhenAbsent(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
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

        $row = $connection->fetchAssociative('SELECT * FROM audit_logs');

        self::assertIsArray($row);
        self::assertNull($row['metadata_json']);
        self::assertNull($row['ip_address']);
    }
}
