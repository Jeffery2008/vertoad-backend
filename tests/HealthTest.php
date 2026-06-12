<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;
use VertoAD\Http\Action\HealthAction;
use VertoAD\Service\RuntimeConfigHealthCheck;

final class HealthTest extends TestCase
{
    public function testHealthEndpointUsesApiEnvelope(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/health');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('ok', $payload['data']['status'] ?? null);
        self::assertSame('vertoad-api', $payload['data']['service'] ?? null);
        self::assertSame('testing', $payload['data']['environment'] ?? null);
        self::assertNull($payload['error'] ?? null);
        self::assertNotEmpty($payload['data']['timestamp'] ?? null);
        self::assertIsArray($payload['meta'] ?? null);
        self::assertNotEmpty($payload['request_id'] ?? null);
    }

    public function testHealthEndpointReportsDegradedWhenRuntimeConfigCheckFails(): void
    {
        $action = new HealthAction(new RuntimeConfigHealthCheck(static function (): void {
            throw new \RuntimeException('Missing required system config: assets.upload_policy.');
        }));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/health');

        $response = $action($request, new \Slim\Psr7\Response());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('runtime_config_unhealthy', $payload['code']);
        self::assertSame('Missing required system config: assets.upload_policy.', $payload['message']);
    }
}
