<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;

final class CronAuthTest extends TestCase
{
    public function testCronStatusRequiresToken(): void
    {
        putenv('CRON_API_TOKEN=test-cron-token');
        putenv('CRON_API_ALLOWED_IPS=127.0.0.1');

        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/cron/status', [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $payload['error']['code'] ?? null);
    }

    public function testCronStatusAcceptsHeaderTokenAndAllowedIp(): void
    {
        putenv('CRON_API_TOKEN=test-cron-token');
        putenv('CRON_API_ALLOWED_IPS=127.0.0.1');

        $app = AppFactory::create();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/status', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Cron-Token', 'test-cron-token');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status'] ?? null);
        self::assertSame('redis', $payload['data']['lock_provider'] ?? null);
        self::assertContains('redis-events-consume', $payload['data']['jobs'] ?? []);
    }

    public function testCronStatusAcceptsQueryTokenWhenHeaderIsMissing(): void
    {
        putenv('CRON_API_TOKEN=test-cron-token');
        putenv('CRON_API_ALLOWED_IPS=127.0.0.1');

        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/cron/status?token=test-cron-token',
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status'] ?? null);
    }

    public function testCronStatusRejectsDisallowedIp(): void
    {
        putenv('CRON_API_TOKEN=test-cron-token');
        putenv('CRON_API_ALLOWED_IPS=127.0.0.1');

        $app = AppFactory::create();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/status', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Cron-Token', 'test-cron-token');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $payload['error']['code'] ?? null);
    }
}
