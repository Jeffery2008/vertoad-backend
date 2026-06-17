<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract\Operations;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OperationsOpenApiContractTest extends TestCase
{
    public function testTask28OperationsPathsAndSchemasAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');

        foreach (
            [
                '/api/v1/operations/summary:',
                '/api/v1/operations/errors:',
                '/api/v1/operations/request-correlations:',
                '/api/v1/operations/request-correlations/{request_id}:',
                '/api/v1/operations/errors/{error_id}/raw-context:',
                '/api/v1/operations/ip-geo/lookup:',
                '/api/v1/operations/config/versions:',
                '/api/v1/operations/config/versions/{version_id}/rollback:',
                '/api/v1/operations/webhooks/deliveries:',
                '/api/v1/operations/webhooks/deliveries/{delivery_id}/retry:',
            ] as $path
        ) {
            self::assertTrue(str_contains($openApi, $path), $path . ' must be documented.');
        }

        foreach (
            [
                'OperationsSummary:',
                'OperationErrorLog:',
                'OperationLogCorrelationListData:',
                'OperationLogCorrelationCounts:',
                'OperationLogCorrelationEntryType:',
                'OperationRequestCorrelation:',
                'OperationIpGeoLookupRequest:',
                'OperationIpGeoLookupResult:',
                'ConfigVersion:',
                'DefaultRevenueShareConfigVersionCreateRequest:',
                'DefaultRevenueShareConfig:',
                'billing.default_revenue_share',
                'WebhookDelivery:',
                'BackupStatus:',
                'RedisHardeningInventory:',
                'raw_context',
                'audit_on_view',
                'request_id',
                'filters',
                'entries',
                'generated_at',
                'ip_address',
                'ServingGeoProviderConfigVersionCreateRequest:',
                'serving.geo_provider',
                'endpoint_template',
                'fields',
                'api_key_env_var',
                'canonical_geo_code',
                'persisted_to_canonical_store',
                'database adapter',
                'optional Redis adapter',
            ] as $contractString
        ) {
            self::assertTrue(str_contains($openApi, $contractString), $contractString . ' must be documented.');
        }

        foreach (
            [
                'ip_geo.provider_registry',
                'credential_env_var',
                'persist_to_canonical_store',
                'OperationRequestCorrelationData:',
                'Canonical geo Redis record TTL',
            ] as $staleString
        ) {
            self::assertStringNotContainsString($staleString, $openApi, $staleString . ' must not remain in the operations contract.');
        }
    }

    public function testRequestCorrelationCollectionAndSingleIdContractsStayDistinct(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 3) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        $collection = $openApi['paths']['/api/v1/operations/request-correlations']['get'] ?? null;
        $single = $openApi['paths']['/api/v1/operations/request-correlations/{request_id}']['get'] ?? null;
        self::assertIsArray($collection);
        self::assertIsArray($single);

        self::assertSame(['ops.dashboard.read.platform'], $collection['x-permissions'] ?? null);
        self::assertSame(['ops.error_log.read_redacted.platform'], $single['x-permissions'] ?? null);
        self::assertSame(
            '#/components/schemas/OperationLogCorrelationListData',
            $collection['responses']['200']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? null,
        );
        self::assertSame(
            '#/components/schemas/OperationRequestCorrelation',
            $single['responses']['200']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? null,
        );

        $listSchema = $openApi['components']['schemas']['OperationLogCorrelationListData'] ?? null;
        self::assertIsArray($listSchema);
        self::assertSame(['filters', 'entries', 'page', 'generated_at'], $listSchema['required'] ?? null);
        foreach (['request_id', 'timeline', 'operation_errors', 'audit_logs', 'webhook_deliveries', 'counts'] as $field) {
            self::assertArrayNotHasKey($field, $listSchema['properties'] ?? []);
        }

        $singleSchema = $openApi['components']['schemas']['OperationRequestCorrelation'] ?? null;
        self::assertIsArray($singleSchema);
        foreach (['request_id', 'timeline', 'operation_errors', 'audit_logs', 'webhook_deliveries', 'system_logs', 'risk_decisions', 'counts'] as $field) {
            self::assertContains($field, $singleSchema['required'] ?? []);
            self::assertArrayHasKey($field, $singleSchema['properties'] ?? []);
        }
        self::assertSame(
            '#/components/schemas/OperationSystemLogEntry',
            $singleSchema['properties']['system_logs']['items']['$ref'] ?? null,
        );
        self::assertSame(
            '#/components/schemas/OperationRiskDecisionLogEntry',
            $singleSchema['properties']['risk_decisions']['items']['$ref'] ?? null,
        );

        $systemLogSchema = $openApi['components']['schemas']['OperationSystemLogEntry'] ?? null;
        self::assertIsArray($systemLogSchema);
        foreach (['http_method', 'ip_address', 'source'] as $field) {
            self::assertContains($field, $systemLogSchema['required'] ?? []);
            self::assertArrayHasKey($field, $systemLogSchema['properties'] ?? []);
        }

        $riskDecisionSchema = $openApi['components']['schemas']['OperationRiskDecisionLogEntry'] ?? null;
        self::assertIsArray($riskDecisionSchema);
        foreach (['http_method', 'user_agent', 'site_id', 'slot_id', 'campaign_id', 'viewer_id', 'ad_decision_id'] as $field) {
            self::assertContains($field, $riskDecisionSchema['required'] ?? []);
            self::assertArrayHasKey($field, $riskDecisionSchema['properties'] ?? []);
        }

        $ipGeoLookupSchema = $openApi['components']['schemas']['OperationIpGeoLookupLogEntry'] ?? null;
        self::assertIsArray($ipGeoLookupSchema);
        foreach (['lookup_id', 'request_id', 'ip_hash', 'ip_address', 'source', 'status', 'provider_id', 'canonical_geo_code', 'queued_at', 'resolved_at', 'attempts', 'last_error', 'next_attempt_at', 'user_agent', 'region_hint', 'request_ids'] as $field) {
            self::assertContains($field, $ipGeoLookupSchema['required'] ?? []);
            self::assertArrayHasKey($field, $ipGeoLookupSchema['properties'] ?? []);
        }
        self::assertContains('pending', $ipGeoLookupSchema['properties']['status']['enum'] ?? []);
        self::assertContains('processing', $ipGeoLookupSchema['properties']['status']['enum'] ?? []);
        self::assertContains('dead', $ipGeoLookupSchema['properties']['status']['enum'] ?? []);
        self::assertNotContains('queued', $ipGeoLookupSchema['properties']['status']['enum'] ?? []);
        self::assertSame(['string', 'null'], $ipGeoLookupSchema['properties']['request_id']['type'] ?? null);

        $webhookSchema = $openApi['components']['schemas']['WebhookDelivery'] ?? null;
        self::assertIsArray($webhookSchema);
        self::assertContains('request_id', $webhookSchema['required'] ?? []);
        self::assertArrayHasKey('request_id', $webhookSchema['properties'] ?? []);
    }
}
