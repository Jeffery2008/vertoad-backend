<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract\Operations;

use PHPUnit\Framework\TestCase;

final class OperationsOpenApiContractTest extends TestCase
{
    public function testTask28OperationsPathsAndSchemasAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');

        foreach (
            [
                '/api/v1/operations/summary:',
                '/api/v1/operations/errors:',
                '/api/v1/operations/errors/{error_id}/raw-context:',
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
                'ConfigVersion:',
                'DefaultRevenueShareConfigVersionCreateRequest:',
                'DefaultRevenueShareConfig:',
                'billing.default_revenue_share',
                'WebhookDelivery:',
                'BackupStatus:',
                'RedisHardeningInventory:',
                'raw_context',
                'audit_on_view',
            ] as $contractString
        ) {
            self::assertTrue(str_contains($openApi, $contractString), $contractString . ' must be documented.');
        }
    }
}
