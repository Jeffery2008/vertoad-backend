<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Operations\OperationRiskDecisionLog;
use VertoAD\Domain\Operations\OperationSystemLog;
use VertoAD\Repository\Operations\InMemoryOperationRiskDecisionLogRepository;
use VertoAD\Repository\Operations\InMemoryOperationSystemLogRepository;

final class OperationCorrelationLogRepositoryTest extends TestCase
{
    public function testSystemLogsPersistAndSearchByCorrelationFields(): void
    {
        $entryClass = 'VertoAD\\Domain\\Operations\\OperationSystemLog';
        $repositoryClass = 'VertoAD\\Repository\\Operations\\DatabaseOperationSystemLogRepository';
        self::assertTrue(class_exists($entryClass), $entryClass . ' must exist.');
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');

        $connection = $this->createConnection();
        $repository = new $repositoryClass($connection);
        $repository->append(new $entryClass(
            log_id: 'syslog_db_1',
            request_id: 'req-system-match',
            level: 'warning',
            message: 'Track payload was normalized',
            endpoint: '/api/v1/ads/track',
            http_method: 'POST',
            ip_address: '198.51.100.11',
            source: 'serving',
            redacted_context: ['token' => '[REDACTED]', 'ip_address' => '198.51.100.11'],
            raw_context: ['token' => 'raw-token', 'ip_address' => '198.51.100.11'],
            occurred_at: new DateTimeImmutable('2026-06-18T01:00:00+00:00'),
        ));
        $repository->append(new $entryClass(
            log_id: 'syslog_db_2',
            request_id: 'req-system-other',
            level: 'info',
            message: 'Other request',
            endpoint: '/api/v1/ads/serve',
            http_method: 'POST',
            ip_address: '198.51.100.12',
            source: 'serving',
            redacted_context: [],
            raw_context: null,
            occurred_at: new DateTimeImmutable('2026-06-18T01:05:00+00:00'),
        ));

        $fresh = new $repositoryClass($connection);
        $matches = $fresh->search([
            'request_id' => 'req-system-match',
            'ip_address' => '198.51.100.11',
            'endpoint' => 'POST:/api/v1/ads/track',
            'occurred_from' => '2026-06-18T00:59:00+00:00',
            'occurred_to' => '2026-06-18T01:01:00+00:00',
            'limit' => 10,
        ]);
        $stored = $fresh->find('syslog_db_1');
        $redactedPayload = $stored?->toArray();
        $rawPayload = $stored?->toArray(true);

        self::assertCount(1, $matches);
        self::assertSame('syslog_db_1', $matches[0]->log_id);
        self::assertSame('warning', $stored?->level);
        self::assertSame('raw-token', $stored?->raw_context['token'] ?? null);
        self::assertIsArray($redactedPayload);
        self::assertArrayNotHasKey('raw_context', $redactedPayload);
        self::assertSame('raw-token', $rawPayload['raw_context']['token'] ?? null);
        self::assertSame('2026-06-18T01:00:00+00:00', $stored?->occurred_at->format(DATE_ATOM));
        self::assertSame([], $fresh->search(['request_id' => 'missing', 'limit' => 10]));
        self::assertNull($fresh->find('missing'));
    }

    public function testRiskDecisionLogsPersistAndSearchBySubjectActionAndEndpoint(): void
    {
        $entryClass = 'VertoAD\\Domain\\Operations\\OperationRiskDecisionLog';
        $repositoryClass = 'VertoAD\\Repository\\Operations\\DatabaseOperationRiskDecisionLogRepository';
        self::assertTrue(class_exists($entryClass), $entryClass . ' must exist.');
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');

        $connection = $this->createConnection();
        $repository = new $repositoryClass($connection);
        $repository->append(new $entryClass(
            decision_id: 'risk_db_1',
            request_id: 'req-risk-match',
            action: 'ads.click.invalid',
            risk_score: 100,
            reason_codes: ['repeat_click_window'],
            subject_type: 'click',
            subject_id: 'clk-risk-1',
            ip_address: '203.0.113.21',
            endpoint: '/api/v1/ads/click',
            http_method: 'GET',
            user_agent: 'Risk Browser',
            site_id: 10,
            slot_id: 20,
            campaign_id: 30,
            viewer_id: 'viewer-risk',
            ad_decision_id: 'ad:decision-risk',
            occurred_at: new DateTimeImmutable('2026-06-18T02:00:00+00:00'),
        ));
        $repository->append(new $entryClass(
            decision_id: 'risk_db_2',
            request_id: 'req-risk-other',
            action: 'ads.serve.risk_rejected',
            risk_score: 90,
            reason_codes: ['fraud_high_risk_viewer'],
            subject_type: 'ad_decision',
            subject_id: 'no-fill:10:20:viewer-risk',
            ip_address: '203.0.113.22',
            endpoint: '/api/v1/ads/serve',
            http_method: 'POST',
            user_agent: null,
            site_id: 10,
            slot_id: 20,
            campaign_id: null,
            viewer_id: 'viewer-risk',
            ad_decision_id: 'no-fill:10:20:viewer-risk',
            occurred_at: new DateTimeImmutable('2026-06-18T02:05:00+00:00'),
        ));

        $fresh = new $repositoryClass($connection);
        $matches = $fresh->search([
            'request_id' => 'req-risk-match',
            'action' => 'ads.click.invalid',
            'subject_type' => 'click',
            'subject_id' => 'clk-risk-1',
            'ip_address' => '203.0.113.21',
            'endpoint' => 'GET:/api/v1/ads/click',
            'occurred_from' => '2026-06-18T01:59:00+00:00',
            'occurred_to' => '2026-06-18T02:01:00+00:00',
            'limit' => 10,
        ]);
        $stored = $fresh->find('risk_db_1');

        self::assertCount(1, $matches);
        self::assertSame('risk_db_1', $matches[0]->decision_id);
        self::assertSame(['repeat_click_window'], $stored?->reason_codes);
        self::assertSame('Risk Browser', $stored?->user_agent);
        self::assertSame('ad:decision-risk', $stored?->ad_decision_id);
        self::assertSame([], $fresh->search(['subject_type' => 'click', 'subject_id' => 'missing', 'limit' => 10]));
        self::assertNull($fresh->find('missing'));
    }

    public function testDatabaseRiskDecisionLogTreatsNonArrayReasonCodeJsonAsEmptyList(): void
    {
        $connection = $this->createConnection();
        $connection->insert('operation_risk_decision_logs', [
            'decision_id' => 'risk_db_non_array_reason',
            'request_id' => 'req-risk-non-array',
            'action' => 'ads.click.invalid',
            'risk_score' => 100,
            'reason_codes_json' => '"repeat_click_window"',
            'subject_type' => 'click',
            'subject_id' => 'clk-non-array',
            'ip_address' => '203.0.113.44',
            'endpoint' => '/api/v1/ads/click',
            'http_method' => 'GET',
            'user_agent' => 'Risk Browser',
            'site_id' => 10,
            'slot_id' => 20,
            'campaign_id' => 30,
            'viewer_id' => 'viewer-risk',
            'ad_decision_id' => 'ad:decision-risk',
            'occurred_at' => '2026-06-18 02:10:00.000000',
        ]);

        $stored = (new \VertoAD\Repository\Operations\DatabaseOperationRiskDecisionLogRepository($connection))
            ->find('risk_db_non_array_reason');

        self::assertSame([], $stored?->reason_codes);
    }

    public function testInMemoryCorrelationLogRepositoriesFilterAndOrderEntries(): void
    {
        $occurredAt = new DateTimeImmutable('2026-06-18T03:00:00+00:00');
        $systemLogs = new InMemoryOperationSystemLogRepository();
        $systemLogs->append(new OperationSystemLog(
            log_id: 'syslog_a',
            request_id: 'req-in-memory',
            level: 'warning',
            message: 'A',
            endpoint: '/api/v1/ads/track',
            http_method: 'POST',
            ip_address: '198.51.100.10',
            source: 'serving',
            redacted_context: [],
            raw_context: null,
            occurred_at: $occurredAt,
        ));
        $systemLogs->append(new OperationSystemLog(
            log_id: 'syslog_b',
            request_id: 'req-in-memory',
            level: 'warning',
            message: 'B',
            endpoint: '/api/v1/ads/track',
            http_method: 'POST',
            ip_address: '198.51.100.10',
            source: 'serving',
            redacted_context: [],
            raw_context: null,
            occurred_at: $occurredAt,
        ));

        $riskLogs = new InMemoryOperationRiskDecisionLogRepository();
        $riskLogs->append(new OperationRiskDecisionLog(
            decision_id: 'risk_a',
            request_id: 'req-in-memory',
            action: 'ads.click.invalid',
            risk_score: 100,
            reason_codes: ['repeat_click_window'],
            subject_type: 'click',
            subject_id: 'clk-in-memory',
            ip_address: '198.51.100.10',
            endpoint: '/api/v1/ads/click',
            http_method: 'GET',
            user_agent: 'Risk Browser',
            site_id: 10,
            slot_id: 20,
            campaign_id: 30,
            viewer_id: 'viewer-risk',
            ad_decision_id: 'ad:decision-risk',
            occurred_at: $occurredAt,
        ));
        $riskLogs->append(new OperationRiskDecisionLog(
            decision_id: 'risk_b',
            request_id: 'req-in-memory',
            action: 'ads.click.invalid',
            risk_score: 100,
            reason_codes: ['repeat_click_window'],
            subject_type: 'click',
            subject_id: 'clk-in-memory',
            ip_address: '198.51.100.10',
            endpoint: '/api/v1/ads/click',
            http_method: 'GET',
            user_agent: 'Risk Browser',
            site_id: 10,
            slot_id: 20,
            campaign_id: 30,
            viewer_id: 'viewer-risk',
            ad_decision_id: 'ad:decision-risk',
            occurred_at: $occurredAt,
        ));

        self::assertSame('syslog_b', $systemLogs->search(['request_id' => 'req-in-memory'])[0]->log_id);
        self::assertSame('risk_b', $riskLogs->search(['request_id' => 'req-in-memory'])[0]->decision_id);
        self::assertSame('syslog_a', $systemLogs->find('syslog_a')?->log_id);
        self::assertSame('risk_a', $riskLogs->find('risk_a')?->decision_id);
        self::assertSame([], $systemLogs->search(['request_id' => 'other']));
        self::assertSame([], $systemLogs->search(['ip_address' => '198.51.100.11']));
        self::assertSame([], $systemLogs->search(['endpoint' => '/api/v1/ads/serve']));
        self::assertSame([], $systemLogs->search(['endpoint' => 'GET:/api/v1/ads/track']));
        self::assertSame([], $systemLogs->search(['occurred_from' => '2026-06-18T03:01:00+00:00']));
        self::assertSame([], $riskLogs->search(['action' => 'ads.serve.risk_rejected']));
        self::assertSame([], $riskLogs->search(['subject_type' => 'ad_decision']));
        self::assertSame([], $riskLogs->search(['subject_id' => 'clk-other']));
        self::assertSame([], $riskLogs->search(['ip_address' => '198.51.100.11']));
        self::assertSame([], $riskLogs->search(['endpoint' => '/api/v1/ads/serve']));
        self::assertSame([], $riskLogs->search(['endpoint' => 'POST:/api/v1/ads/click']));
        self::assertSame([], $riskLogs->search(['occurred_from' => '2026-06-18T03:01:00+00:00']));
    }

    public function testMigrationDefinesDurableCorrelationLogTables(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260618050000_create_operation_correlation_log_tables.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table operation_system_logs',
            'log_id varchar(160) not null',
            'request_id varchar(160) not null',
            'redacted_context_json json not null',
            'raw_context_json json null',
            'idx_operation_system_logs_request',
            'idx_operation_system_logs_endpoint',
            'create table operation_risk_decision_logs',
            'decision_id varchar(160) not null',
            'reason_codes_json json not null',
            'ad_decision_id varchar(160) null',
            'idx_operation_risk_decision_logs_request',
            'idx_operation_risk_decision_logs_subject',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
        self::assertLessThan(
            strpos($sql, 'drop table if exists operation_system_logs'),
            strpos($sql, 'drop table if exists operation_risk_decision_logs'),
        );
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE operation_system_logs (
                log_id VARCHAR(160) PRIMARY KEY,
                request_id VARCHAR(160) NOT NULL,
                level VARCHAR(32) NOT NULL,
                message VARCHAR(1024) NOT NULL,
                endpoint VARCHAR(255) NULL,
                http_method VARCHAR(16) NULL,
                ip_address VARCHAR(45) NULL,
                source VARCHAR(64) NOT NULL,
                redacted_context_json TEXT NOT NULL,
                raw_context_json TEXT NULL,
                occurred_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE operation_risk_decision_logs (
                decision_id VARCHAR(160) PRIMARY KEY,
                request_id VARCHAR(160) NOT NULL,
                action VARCHAR(120) NOT NULL,
                risk_score INTEGER NULL,
                reason_codes_json TEXT NOT NULL,
                subject_type VARCHAR(64) NULL,
                subject_id VARCHAR(160) NULL,
                ip_address VARCHAR(45) NULL,
                endpoint VARCHAR(255) NULL,
                http_method VARCHAR(16) NULL,
                user_agent VARCHAR(512) NULL,
                site_id INTEGER NULL,
                slot_id INTEGER NULL,
                campaign_id INTEGER NULL,
                viewer_id VARCHAR(160) NULL,
                ad_decision_id VARCHAR(160) NULL,
                occurred_at DATETIME NOT NULL
            )',
        );

        return $connection;
    }
}
