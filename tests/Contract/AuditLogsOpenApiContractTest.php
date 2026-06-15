<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class AuditLogsOpenApiContractTest extends TestCase
{
    public function testAuditLogQueryPathAndSchemasAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');

        foreach (
            [
                '/api/v1/audit-logs:',
                'operationId: listAuditLogs',
                '- audit.read.platform',
                'AuditLogListData:',
                'AuditLogItem:',
                'AuditLogPage:',
                'context_redacted',
                'organization_id',
                'created_from',
                'created_to',
            ] as $contractString
        ) {
            self::assertStringContainsString($contractString, $openApi, $contractString . ' must be documented.');
        }
    }
}
