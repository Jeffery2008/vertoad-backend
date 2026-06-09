<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class WebhooksOpenApiContractTest extends TestCase
{
    public function testAdvertiserWebhookEndpointPathsSchemasAndEnvelopeContractsAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');

        foreach ([
            '/api/v1/webhooks/endpoints:',
            '/api/v1/webhooks/endpoints/{endpoint_id}:',
            '/api/v1/webhooks/endpoints/{endpoint_id}/rotate-secret:',
            '/api/v1/webhooks/endpoints/{endpoint_id}/test:',
            '/api/v1/webhooks/deliveries:',
            'operationId: listWebhookEndpoints',
            'operationId: createWebhookEndpoint',
            'operationId: updateWebhookEndpoint',
            'operationId: rotateWebhookEndpointSecret',
            'operationId: testWebhookEndpoint',
            'operationId: listOwnWebhookDeliveries',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $openApi, $fragment . ' must be documented.');
        }

        foreach ([
            'WebhookEndpointCreateRequest:',
            'WebhookEndpointUpdateRequest:',
            'WebhookEndpoint:',
            'WebhookEndpointListData:',
            'WebhookEndpointSecretData:',
            'WebhookEndpointTestData:',
            'OwnWebhookDeliveriesData:',
            'signing_secret',
            'secret_preview',
            'secret_rotated_at',
            'endpoint_id',
            'organization_id',
            'enabled',
            'next_attempt_at',
            'last_attempt_at',
            'last_status_code',
        ] as $schemaFragment) {
            self::assertStringContainsString($schemaFragment, $openApi, $schemaFragment . ' must be documented.');
        }

        self::assertStringNotContainsString('WebhookSubscription', $openApi);
        self::assertStringNotContainsString('/api/v1/webhooks/subscriptions', $openApi);
        self::assertStringNotContainsString('secret_hash', $openApi);
        self::assertStringNotContainsString('encrypted_signing_secret', $openApi);
    }
}
