<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Operations\OperationErrorLog;
use VertoAD\Repository\Operations\DatabaseOperationErrorLogRepository;

final class DatabaseOperationErrorLogRepositoryTest extends TestCase
{
    public function testOperationErrorsSurviveRepositoryInstancesWithRawContextSeparated(): void
    {
        $connection = $this->createConnection();
        $repository = new DatabaseOperationErrorLogRepository($connection);
        $entry = new OperationErrorLog(
            error_id: 'operr_db_1',
            request_id: 'req-db-1',
            severity: 'critical',
            message: 'PDOException: failed query',
            redacted_context: ['authorization' => '[REDACTED]', 'safe' => 'visible'],
            raw_context: ['authorization' => 'Bearer raw-token', 'safe' => 'visible'],
            source: 'api',
            occurred_at: new DateTimeImmutable('2026-06-09T03:00:00+00:00'),
        );

        $repository->append($entry);
        $fresh = new DatabaseOperationErrorLogRepository($connection);

        $stored = $fresh->find('operr_db_1');
        self::assertNotNull($stored);
        self::assertSame('req-db-1', $stored->request_id);
        self::assertSame('critical', $stored->severity);
        self::assertSame('api', $stored->source);
        self::assertSame('[REDACTED]', $stored->redacted_context['authorization'] ?? null);
        self::assertSame('Bearer raw-token', $stored->raw_context['authorization'] ?? null);
        self::assertSame('2026-06-09T03:00:00+00:00', $stored->occurred_at->format(DATE_ATOM));
        self::assertSame(['operr_db_1'], array_map(
            static fn (OperationErrorLog $log): string => $log->error_id,
            $fresh->all(),
        ));
        self::assertNull($fresh->find('missing'));
    }

    public function testOperationErrorMigrationDefinesDurableErrorLog(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260609100000_create_operation_error_log_tables.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table operation_error_logs',
            'error_id varchar(160) not null',
            'request_id varchar(160) not null',
            'severity varchar(32) not null',
            'message varchar(1024) not null',
            'redacted_context_json json not null',
            'raw_context_json json null',
            'source varchar(32) not null',
            'occurred_at datetime(6) not null',
            'idx_operation_error_logs_occurred',
            'idx_operation_error_logs_request',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE operation_error_logs (
                error_id VARCHAR(160) PRIMARY KEY,
                request_id VARCHAR(160) NOT NULL,
                severity VARCHAR(32) NOT NULL,
                message VARCHAR(1024) NOT NULL,
                redacted_context_json TEXT NOT NULL,
                raw_context_json TEXT NULL,
                source VARCHAR(32) NOT NULL,
                occurred_at DATETIME NOT NULL
            )',
        );

        return $connection;
    }
}
