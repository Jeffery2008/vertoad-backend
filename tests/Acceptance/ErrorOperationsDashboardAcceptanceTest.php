<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\Tests\Acceptance\ErrorOperationsDashboard\ErrorOperationsDashboardAcceptanceHarness;

#[Group('external-tools-integration')]
#[Group('error-operations-dashboard-acceptance')]
final class ErrorOperationsDashboardAcceptanceTest extends TestCase
{
    private ?ErrorOperationsDashboardAcceptanceHarness $harness = null;
    private bool $scenarioCompleted = false;

    protected function setUp(): void
    {
        if (getenv('VERTOAD_ERROR_OPERATIONS_DASHBOARD_ACCEPTANCE') !== '1') {
            self::markTestSkipped(
                'Set VERTOAD_ERROR_OPERATIONS_DASHBOARD_ACCEPTANCE=1 with real MySQL, Redis, Node, and Chromium settings.',
            );
        }

        $this->harness = ErrorOperationsDashboardAcceptanceHarness::boot(dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        if ($this->harness === null) {
            return;
        }

        $harness = $this->harness;
        $this->harness = null;
        $evidence = $harness->cleanup();
        self::assertSame($evidence['redis_keys_before'], $evidence['redis_keys_deleted']);
        self::assertSame(0, $evidence['redis_keys_after']);
        self::assertSame(1, $evidence['database_before']);
        self::assertSame(0, $evidence['database_after']);
        self::assertSame(1, $evidence['workspace_before']);
        self::assertSame(0, $evidence['workspace_after']);
        self::assertSame(1, $evidence['server_stopped']);
        if ($this->scenarioCompleted) {
            self::assertGreaterThan(0, $evidence['redis_keys_before']);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrownErrorsReachMysqlAndTheSuperAdminOperationsDashboard(): void
    {
        $harness = $this->harness();
        self::assertMatchesRegularExpression('/^8\./', $harness->mysqlVersion());
        self::assertMatchesRegularExpression('/^vertoad_error_ops_[a-f0-9]{16}$/', $harness->databaseName());
        self::assertMatchesRegularExpression(
            '/^vertoad:acceptance:error-operations:[a-f0-9]{16}:$/',
            $harness->redisPrefix(),
        );
        self::assertGreaterThanOrEqual(27, $harness->migrationCount());

        $evidence = $harness->runBrowserScenario();
        self::assertSame(200, $evidence['login']['status'] ?? null);
        self::assertSame('Bearer', $evidence['login']['tokenType'] ?? null);
        self::assertSame(200, $evidence['identity']['status'] ?? null);
        self::assertTrue($evidence['identity']['isSuperAdmin'] ?? false);
        self::assertSame($harness->superAdminUserId(), $evidence['identity']['userId'] ?? null);

        foreach (['api', 'php'] as $source) {
            $trigger = $evidence['triggers'][$source] ?? [];
            self::assertSame(500, $trigger['status'] ?? null);
            self::assertTrue($trigger['headerMatchesBody'] ?? false);
            self::assertSame('operation_error', $trigger['code'] ?? null);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) ($trigger['requestId'] ?? ''));
            self::assertMatchesRegularExpression('/^operr_[a-f0-9]{40}$/', (string) ($trigger['errorId'] ?? ''));
        }
        self::assertNotSame(
            $evidence['triggers']['api']['requestId'] ?? null,
            $evidence['triggers']['php']['requestId'] ?? null,
        );

        self::assertTrue($evidence['dashboard']['apiErrorVisible'] ?? false);
        self::assertTrue($evidence['dashboard']['phpErrorVisible'] ?? false);
        self::assertTrue($evidence['dashboard']['markerHiddenBeforeRawView'] ?? false);
        self::assertTrue($evidence['dashboard']['rawContextVisibleAfterAuditView'] ?? false);
        self::assertTrue($evidence['dashboard']['redactedListVerified'] ?? false);
        self::assertTrue($evidence['dashboard']['redactedCorrelationVerified'] ?? false);
        self::assertTrue($evidence['dashboard']['timelineVisible'] ?? false);
        self::assertContains('operation_error', $evidence['dashboard']['timelineTypes'] ?? []);
        self::assertContains('system_log', $evidence['dashboard']['timelineTypes'] ?? []);
        self::assertContains('audit_log', $evidence['dashboard']['timelineTypes'] ?? []);
        self::assertSame(
            [$evidence['triggers']['php']['requestId']],
            array_values(array_unique($evidence['dashboard']['timelineRequestIds'] ?? [])),
        );
        self::assertSame(1, $evidence['correlation']['counts']['operation_errors'] ?? null);
        self::assertSame(1, $evidence['correlation']['counts']['system_logs'] ?? null);
        self::assertSame(1, $evidence['correlation']['counts']['audit_logs'] ?? null);
        self::assertFileExists((string) ($evidence['dashboard']['screenshotPath'] ?? ''));
        self::assertTrue($evidence['fixtureWorkspaceRemoved'] ?? false);
        self::assertSame([], $evidence['consoleIssues'] ?? null);

        $requestCounts = $evidence['requestCounts'] ?? [];
        self::assertSame(1, $requestCounts['login'] ?? null);
        self::assertSame(1, $requestCounts['me'] ?? null);
        self::assertSame(1, $requestCounts['triggerApi'] ?? null);
        self::assertSame(1, $requestCounts['triggerPhp'] ?? null);
        self::assertSame(1, $requestCounts['summary'] ?? null);
        self::assertSame(2, $requestCounts['errors'] ?? null);
        self::assertSame(1, $requestCounts['correlation'] ?? null);
        self::assertSame(1, $requestCounts['rawContext'] ?? null);
        self::assertGreaterThanOrEqual(6, $requestCounts['authenticatedOperationsRequests'] ?? 0);
        self::assertSame(0, $requestCounts['unauthenticatedOperationsRequests'] ?? null);

        $this->assertMysqlErrorAndSystemLogs($evidence);
        $this->assertOrdinaryOperatorOnlySeesRedactedContext($evidence);
        $this->assertRawContextViewAudit($evidence);
        self::assertGreaterThanOrEqual(2, (int) $harness->connection()->fetchOne('SELECT COUNT(*) FROM first_party_sessions'));

        $this->scenarioCompleted = true;
    }

    /** @param array<string, mixed> $evidence */
    private function assertMysqlErrorAndSystemLogs(array $evidence): void
    {
        $harness = $this->harness();
        $connection = $harness->connection();
        $marker = $harness->contextMarker();
        $expectedSources = ['api' => 'api', 'php' => 'php'];

        foreach ($expectedSources as $triggerName => $storedSource) {
            $trigger = $evidence['triggers'][$triggerName];
            $error = $connection->fetchAssociative(
                'SELECT error_id, request_id, severity, message, redacted_context_json, raw_context_json, source '
                . 'FROM operation_error_logs WHERE error_id = ?',
                [$trigger['errorId']],
            );
            self::assertIsArray($error);
            self::assertSame($trigger['requestId'], $error['request_id']);
            self::assertSame($storedSource, $error['source']);
            self::assertSame($triggerName === 'php' ? 'critical' : 'error', $error['severity']);
            $redacted = $this->decodeObject((string) $error['redacted_context_json']);
            $raw = $this->decodeObject((string) $error['raw_context_json']);
            self::assertSame('[REDACTED]', $redacted['headers']['x-secret-token'] ?? null);
            self::assertSame($marker, $raw['headers']['x-secret-token'] ?? null);
            self::assertSame('/__acceptance/error/' . $triggerName, $raw['path'] ?? null);
            self::assertSame('GET', $raw['method'] ?? null);
            self::assertSame('127.0.0.1', $raw['ip_address'] ?? null);
            self::assertStringContainsString('Chrome', (string) ($raw['user_agent'] ?? ''));

            $system = $connection->fetchAssociative(
                'SELECT log_id, request_id, level, message, endpoint, http_method, ip_address, source, '
                . 'redacted_context_json, raw_context_json FROM operation_system_logs WHERE request_id = ?',
                [$trigger['requestId']],
            );
            self::assertIsArray($system);
            self::assertSame('syslog_' . sha1((string) $trigger['errorId']), $system['log_id']);
            self::assertSame($trigger['requestId'], $system['request_id']);
            self::assertSame($error['severity'], $system['level']);
            self::assertSame($error['message'], $system['message']);
            self::assertSame('/__acceptance/error/' . $triggerName, $system['endpoint']);
            self::assertSame('GET', $system['http_method']);
            self::assertSame('127.0.0.1', $system['ip_address']);
            self::assertSame($storedSource, $system['source']);
            self::assertSame('[REDACTED]', $this->decodeObject((string) $system['redacted_context_json'])['headers']['x-secret-token'] ?? null);
            self::assertSame($marker, $this->decodeObject((string) $system['raw_context_json'])['headers']['x-secret-token'] ?? null);

            $audit = $connection->fetchAssociative(
                'SELECT request_id, action, subject_type, metadata_json FROM audit_logs '
                . 'WHERE request_id = ? AND action = ?',
                [$trigger['requestId'], 'acceptance.error.' . $triggerName . '.before_throw'],
            );
            self::assertIsArray($audit);
            self::assertSame($trigger['requestId'], $audit['request_id']);
            self::assertSame('operation_acceptance', $audit['subject_type']);
            self::assertSame(
                'GET:/__acceptance/error/' . $triggerName,
                $this->decodeObject((string) $audit['metadata_json'])['endpoint'] ?? null,
            );
        }

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM operation_error_logs'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM operation_system_logs'));
    }

    /** @param array<string, mixed> $evidence */
    private function assertOrdinaryOperatorOnlySeesRedactedContext(array $evidence): void
    {
        $trigger = $evidence['triggers']['php'];
        $ordinary = $this->harness()->operatorVisibilityEvidence($trigger['requestId'], $trigger['errorId']);
        self::assertSame(200, $ordinary['login_status']);
        self::assertSame(200, $ordinary['me_status']);
        self::assertFalse($ordinary['is_super_admin']);

        self::assertSame(200, $ordinary['errors']['status']);
        $listed = $ordinary['errors']['body']['data']['errors'] ?? [];
        self::assertCount(1, $listed);
        self::assertSame($trigger['errorId'], $listed[0]['error_id'] ?? null);
        self::assertNull($listed[0]['raw_context'] ?? null);
        self::assertSame('[REDACTED]', $listed[0]['redacted_context']['headers']['x-secret-token'] ?? null);

        self::assertSame(200, $ordinary['correlation']['status']);
        $correlation = $ordinary['correlation']['body']['data'] ?? [];
        self::assertSame($trigger['requestId'], $correlation['request_id'] ?? null);
        self::assertSame(1, $correlation['counts']['operation_errors'] ?? null);
        self::assertSame(1, $correlation['counts']['system_logs'] ?? null);
        self::assertGreaterThanOrEqual(2, $correlation['counts']['audit_logs'] ?? 0);
        self::assertNull($correlation['operation_errors'][0]['raw_context'] ?? null);
        self::assertArrayNotHasKey('raw_context', $correlation['system_logs'][0] ?? []);
        self::assertStringNotContainsString(
            $this->harness()->contextMarker(),
            json_encode($ordinary['errors']['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        self::assertStringNotContainsString(
            $this->harness()->contextMarker(),
            json_encode($ordinary['correlation']['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        self::assertSame(403, $ordinary['raw']['status']);
        self::assertSame('forbidden', $ordinary['raw']['body']['error']['code'] ?? null);
        self::assertStringNotContainsString(
            $this->harness()->contextMarker(),
            json_encode($ordinary['raw']['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /** @param array<string, mixed> $evidence */
    private function assertRawContextViewAudit(array $evidence): void
    {
        $trigger = $evidence['triggers']['php'];
        $rows = $this->harness()->connection()->fetchAllAssociative(
            'SELECT actor_user_id, request_id, action, subject_type, metadata_json FROM audit_logs '
            . 'WHERE request_id = ? ORDER BY id',
            [$trigger['requestId']],
        );
        self::assertCount(2, $rows);
        self::assertSame('acceptance.error.php.before_throw', $rows[0]['action']);
        self::assertSame('operations.error.raw_context.viewed', $rows[1]['action']);
        self::assertSame($this->harness()->superAdminUserId(), (int) $rows[1]['actor_user_id']);
        self::assertSame('operation_error_log', $rows[1]['subject_type']);
        $metadata = $this->decodeObject((string) $rows[1]['metadata_json']);
        self::assertSame($trigger['errorId'], $metadata['error_id'] ?? null);
        self::assertSame($trigger['requestId'], $metadata['request_id'] ?? null);
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function harness(): ErrorOperationsDashboardAcceptanceHarness
    {
        return $this->harness ?? throw new \LogicException('The error operations acceptance harness is unavailable.');
    }
}
