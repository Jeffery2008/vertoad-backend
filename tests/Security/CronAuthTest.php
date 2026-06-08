<?php

declare(strict_types=1);

namespace VertoAD\Tests\Security;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;

final class CronAuthTest extends TestCase
{
    public function testCronRejectsMissingTokenWithEnvelopeAndRequestId(): void
    {
        putenv('CRON_API_TOKEN=test-cron-token');
        putenv('CRON_API_ALLOWED_IPS=127.0.0.1');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/status', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Request-Id', 'req-cron-auth');

        $response = AppFactory::create()->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('unauthorized', $payload['error']['code'] ?? null);
        self::assertSame('req-cron-auth', $payload['request_id'] ?? null);
        self::assertSame('req-cron-auth', $response->getHeaderLine('X-Request-Id'));
    }

    public function testCronRejectsDisallowedIpWithEnvelope(): void
    {
        putenv('CRON_API_TOKEN=test-cron-token');
        putenv('CRON_API_ALLOWED_IPS=127.0.0.1');

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/status', ['REMOTE_ADDR' => '203.0.113.44'])
            ->withHeader('X-Cron-Token', 'test-cron-token');

        $response = AppFactory::create()->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $payload['error']['code'] ?? null);
        self::assertNotEmpty($payload['request_id'] ?? null);
    }
}
