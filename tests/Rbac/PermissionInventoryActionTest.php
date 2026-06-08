<?php

declare(strict_types=1);

namespace VertoAD\Tests\Rbac;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;

final class PermissionInventoryActionTest extends TestCase
{
    public function testPermissionInventoryEndpointReturnsPublicSeedMetadataInApiEnvelope(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/permissions')
            ->withHeader('X-Request-Id', 'permission-inventory-request');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('permission-inventory-request', $payload['request_id'] ?? null);
        self::assertNull($payload['error'] ?? null);
        self::assertSame(['api_version' => 'v1'], $payload['meta'] ?? null);
        self::assertIsArray($payload['data'] ?? null);

        $permissions = $this->indexByCode($payload['data']);

        foreach ([
            'rbac.permission.read.platform',
            'billing.recharge_key.view_plaintext.platform',
            'publisher.slot.write.own',
            'ops.error_log.view_raw.platform',
            'security.event.read.platform',
        ] as $requiredCode) {
            self::assertArrayHasKey($requiredCode, $permissions);
        }

        self::assertSame([
            'code',
            'domain',
            'scope',
            'description',
            'sensitive',
            'audit_required',
        ], array_keys($permissions['billing.recharge_key.view_plaintext.platform']));
        self::assertSame('billing', $permissions['billing.recharge_key.view_plaintext.platform']['domain']);
        self::assertSame('platform', $permissions['billing.recharge_key.view_plaintext.platform']['scope']);
        self::assertTrue($permissions['billing.recharge_key.view_plaintext.platform']['sensitive']);
        self::assertTrue($permissions['billing.recharge_key.view_plaintext.platform']['audit_required']);
        self::assertFalse($permissions['rbac.permission.read.platform']['sensitive']);
        self::assertFalse($permissions['rbac.permission.read.platform']['audit_required']);
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return array<string, array<string, mixed>>
     */
    private function indexByCode(array $permissions): array
    {
        $indexed = [];

        foreach ($permissions as $permission) {
            self::assertSame([
                'code',
                'domain',
                'scope',
                'description',
                'sensitive',
                'audit_required',
            ], array_keys($permission));
            self::assertIsString($permission['code']);
            self::assertIsString($permission['domain']);
            self::assertIsString($permission['scope']);
            self::assertIsString($permission['description']);
            self::assertIsBool($permission['sensitive']);
            self::assertIsBool($permission['audit_required']);

            $indexed[$permission['code']] = $permission;
        }

        return $indexed;
    }
}
