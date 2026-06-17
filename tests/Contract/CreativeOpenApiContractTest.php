<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CreativeOpenApiContractTest extends TestCase
{
    public function testCreativeTemplateDesignVersionPathsAndSchemasAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');

        foreach (
            [
                '/api/v1/creative/templates:',
                '/api/v1/creative/designs:',
                '/api/v1/creative/designs/{design_id}/versions:',
                'operationId: listCreativeTemplates',
                'operationId: createCreativeTemplate',
                'operationId: createCreativeDesign',
                'operationId: listCreativeDesignVersions',
                'operationId: createCreativeDesignVersion',
                'CreativeTemplateCreateRequest:',
                'CreativeDesignCreateRequest:',
                'CreativeDesignVersionCreateRequest:',
                'CreativeTemplate:',
                'CreativeDesign:',
                'CreativeDesignVersion:',
                'CreativeTemplateListData:',
                'CreativeDesignCreateData:',
                'CreativeDesignVersionListData:',
                'creative.template.read.own',
                'creative.template.write.own',
                'creative.template.manage.platform',
                'creative.design.read.own',
                'creative.design.write.own',
            ] as $contractString
        ) {
            self::assertStringContainsString($contractString, $openApi, $contractString . ' must be documented.');
        }
    }

    public function testCreativeOperationsDocumentBearerAuthAndOrganizationQueries(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');

        $templates = $this->operationBlock($openApi, 'operationId: listCreativeTemplates', 'operationId: createCreativeTemplate');
        self::assertStringContainsString('BearerAuth: []', $templates);
        self::assertStringContainsString('name: organization_id', $templates);
        self::assertStringContainsString('name: scope', $templates);

        $createTemplate = $this->operationBlock($openApi, 'operationId: createCreativeTemplate', '  /api/v1/creative/designs:');
        self::assertStringContainsString('BearerAuth: []', $createTemplate);
        self::assertStringContainsString('CreativeTemplateCreateRequest', $createTemplate);
        self::assertStringContainsString('"201":', $createTemplate);
        self::assertStringContainsString('default:', $createTemplate);

        $createDesign = $this->operationBlock($openApi, 'operationId: createCreativeDesign', '  /api/v1/creative/designs/{design_id}/versions:');
        self::assertStringContainsString('BearerAuth: []', $createDesign);
        self::assertStringContainsString('CreativeDesignCreateRequest', $createDesign);

        $versions = $this->operationBlock($openApi, 'operationId: listCreativeDesignVersions', 'operationId: createCreativeDesignVersion');
        self::assertStringContainsString('BearerAuth: []', $versions);
        self::assertStringContainsString('name: organization_id', $versions);
        self::assertStringContainsString('name: design_id', $versions);
    }

    public function testCreativeSnapshotUrlsAreDocumentedAsHttpsOnly(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $schemas = $this->operationBlock($openApi, 'CreativeTemplateCreateRequest:', 'CreativeTemplateListData:');

        self::assertStringContainsString('pattern: "^https://"', $schemas);
        self::assertStringContainsString('Inline data URLs are not accepted.', $schemas);
        self::assertGreaterThanOrEqual(5, substr_count($schemas, 'maxLength: 1024'));
    }

    public function testCreativeOperationsUseStructuredEnvelopeSchemasAndErrorStatuses(): void
    {
        $openApi = $this->parsedOpenApi();

        $this->assertSuccessDataSchema(
            $openApi,
            '/api/v1/creative/templates',
            'get',
            '200',
            '#/components/schemas/CreativeTemplateListData',
        );
        $this->assertSuccessDataSchema(
            $openApi,
            '/api/v1/creative/templates',
            'post',
            '201',
            '#/components/schemas/CreativeTemplate',
        );
        $this->assertSuccessDataSchema(
            $openApi,
            '/api/v1/creative/designs',
            'post',
            '201',
            '#/components/schemas/CreativeDesignCreateData',
        );
        $this->assertSuccessDataSchema(
            $openApi,
            '/api/v1/creative/designs/{design_id}/versions',
            'get',
            '200',
            '#/components/schemas/CreativeDesignVersionListData',
        );
        $this->assertSuccessDataSchema(
            $openApi,
            '/api/v1/creative/designs/{design_id}/versions',
            'post',
            '201',
            '#/components/schemas/CreativeDesignVersion',
        );

        foreach (
            [
                ['get', '/api/v1/creative/templates', ['400', '401', '403', '422', 'default']],
                ['post', '/api/v1/creative/templates', ['400', '401', '403', '422', 'default']],
                ['post', '/api/v1/creative/designs', ['400', '401', '403', '422', 'default']],
                ['get', '/api/v1/creative/designs/{design_id}/versions', ['400', '401', '403', '404', '422', 'default']],
                ['post', '/api/v1/creative/designs/{design_id}/versions', ['400', '401', '403', '404', '422', 'default']],
            ] as [$method, $path, $statuses]
        ) {
            $responses = $openApi['paths'][$path][$method]['responses'] ?? null;
            self::assertIsArray($responses);
            foreach ($statuses as $status) {
                self::assertSame(
                    '#/components/responses/Error',
                    $responses[$status]['$ref'] ?? null,
                    strtoupper($method) . ' ' . $path . ' must document ' . $status . ' with the shared error envelope.',
                );
            }
        }
    }

    public function testCreativeSchemasMatchNullableFieldsAndAppendOnlyVersionContract(): void
    {
        $schemas = $this->parsedOpenApi()['components']['schemas'] ?? null;
        self::assertIsArray($schemas);

        $templateCreate = $schemas['CreativeTemplateCreateRequest'] ?? null;
        self::assertIsArray($templateCreate);
        self::assertSame(['platform', 'organization'], $templateCreate['properties']['scope']['enum'] ?? null);
        self::assertSame(['integer', 'null'], $templateCreate['properties']['organization_id']['type'] ?? null);
        $this->assertHttpsOnlySnapshotUrlSchema($templateCreate, 'CreativeTemplateCreateRequest');
        self::assertSame(false, $templateCreate['additionalProperties'] ?? null);

        $designCreate = $schemas['CreativeDesignCreateRequest'] ?? null;
        self::assertIsArray($designCreate);
        foreach (['organization_id', 'name', 'fabric_json'] as $requiredField) {
            self::assertContains($requiredField, $designCreate['required'] ?? []);
        }
        self::assertArrayHasKey('template_id', $designCreate['properties'] ?? []);
        $this->assertHttpsOnlySnapshotUrlSchema($designCreate, 'CreativeDesignCreateRequest');
        self::assertSame(false, $designCreate['additionalProperties'] ?? null);

        $versionCreate = $schemas['CreativeDesignVersionCreateRequest'] ?? null;
        self::assertIsArray($versionCreate);
        self::assertSame(['organization_id', 'fabric_json'], $versionCreate['required'] ?? null);
        $this->assertHttpsOnlySnapshotUrlSchema($versionCreate, 'CreativeDesignVersionCreateRequest');
        self::assertArrayNotHasKey(
            'version_number',
            $versionCreate['properties'] ?? [],
            'Clients must not supply design version numbers; backend appends the next version transactionally.',
        );
        self::assertSame(false, $versionCreate['additionalProperties'] ?? null);

        $version = $schemas['CreativeDesignVersion'] ?? null;
        self::assertIsArray($version);
        self::assertSame(1, $version['properties']['version_number']['minimum'] ?? null);
        $this->assertHttpsOnlySnapshotUrlSchema($version, 'CreativeDesignVersion');
        self::assertSame(['string', 'null'], $version['properties']['change_summary']['type'] ?? null);

        $template = $schemas['CreativeTemplate'] ?? null;
        self::assertIsArray($template);
        $this->assertHttpsOnlySnapshotUrlSchema($template, 'CreativeTemplate');
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedOpenApi(): array
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);
        self::assertSame('3.1.0', $openApi['openapi'] ?? null);

        return $openApi;
    }

    /**
     * @param array<string, mixed> $openApi
     */
    private function assertSuccessDataSchema(array $openApi, string $path, string $method, string $status, string $dataSchemaRef): void
    {
        $schema = $openApi['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'] ?? null;
        self::assertIsArray($schema, strtoupper($method) . ' ' . $path . ' must document JSON response schema.');

        self::assertSame(
            '#/components/schemas/SuccessEnvelope',
            $schema['allOf'][0]['$ref'] ?? null,
            strtoupper($method) . ' ' . $path . ' must wrap success payloads in SuccessEnvelope.',
        );
        self::assertSame(
            $dataSchemaRef,
            $schema['allOf'][1]['properties']['data']['$ref'] ?? null,
            strtoupper($method) . ' ' . $path . ' must document the expected data schema.',
        );
    }

    private function operationBlock(string $openApi, string $start, string $end): string
    {
        $startOffset = strpos($openApi, $start);
        self::assertNotFalse($startOffset, $start . ' must be documented.');

        $endOffset = strpos($openApi, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end . ' must follow ' . $start . '.');

        return substr($openApi, $startOffset, $endOffset - $startOffset);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function assertHttpsOnlySnapshotUrlSchema(array $schema, string $schemaName): void
    {
        $snapshotUrl = $schema['properties']['snapshot_url'] ?? null;
        self::assertIsArray($snapshotUrl, $schemaName . '.snapshot_url must be documented.');
        self::assertSame(['string', 'null'], $snapshotUrl['type'] ?? null, $schemaName . '.snapshot_url must be nullable.');
        self::assertSame('uri', $snapshotUrl['format'] ?? null, $schemaName . '.snapshot_url must be a URI.');
        self::assertSame(1024, $snapshotUrl['maxLength'] ?? null, $schemaName . '.snapshot_url must match backend length validation.');
        self::assertSame('^https://', $snapshotUrl['pattern'] ?? null, $schemaName . '.snapshot_url must reject inline and insecure URLs.');
    }
}
