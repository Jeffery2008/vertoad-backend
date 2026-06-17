<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    public function testAuditLogQueryFiltersAreDocumentedOnAuditLogPath(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        $operation = $openApi['paths']['/api/v1/audit-logs']['get'] ?? null;
        self::assertIsArray($operation);

        $parameters = $operation['parameters'] ?? null;
        self::assertIsArray($parameters);

        $queryParameterNames = array_map(
            static fn (array $parameter): ?string => ($parameter['in'] ?? null) === 'query' ? ($parameter['name'] ?? null) : null,
            $parameters,
        );

        foreach (['request_id', 'ip_address', 'endpoint'] as $filter) {
            self::assertContains($filter, $queryParameterNames, $filter . ' query filter must be documented.');
        }
    }
}
