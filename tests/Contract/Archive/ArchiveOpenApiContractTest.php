<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract\Archive;

use PHPUnit\Framework\TestCase;

final class ArchiveOpenApiContractTest extends TestCase
{
    public function testArchiveAndColdQueryPathsDocumentAsyncContractsAndSchemas(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');

        foreach ([
            '/api/v1/archive/jobs:',
            '/api/v1/archive/manifests/{manifest_id}:',
            '/api/v1/archive/cold-queries:',
            '/api/v1/archive/cold-queries/{job_id}:',
        ] as $path) {
            self::assertStringContainsString($path, $openApi);
        }

        foreach ([
            'ArchiveManifest:',
            'ArchivePartition:',
            'CreateColdQueryRequest:',
            'ColdQueryJob:',
        ] as $schema) {
            self::assertStringContainsString($schema, $openApi);
        }

        self::assertStringContainsString('event_type/date/hour', $openApi);
        self::assertStringContainsString('queued', $openApi);
        self::assertStringContainsString('running', $openApi);
        self::assertStringContainsString('failed', $openApi);
        self::assertStringContainsString('result_object_key', $openApi);
        self::assertStringContainsString('error_message', $openApi);
        self::assertStringContainsString('checksum', $openApi);
        self::assertStringContainsString('byte_count', $openApi);
        self::assertStringContainsString('row_count', $openApi);
    }
}
