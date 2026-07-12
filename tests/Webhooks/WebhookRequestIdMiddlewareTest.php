<?php

declare(strict_types=1);

namespace VertoAD\Tests\Webhooks;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use VertoAD\Http\Middleware\WebhookRequestIdMiddleware;
use VertoAD\Http\RequestIdContext;

final class WebhookRequestIdMiddlewareTest extends TestCase
{
    public function testBindsAndClearsRequestIdForMysqlApiRequest(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            },
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/campaigns')
            ->withAttribute(RequestIdContext::ATTRIBUTE, 'req-business-event');

        $response = (new WebhookRequestIdMiddleware($connection))->process($request, $this->handler());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([
            ['SET @vertoad_request_id = ?', ['req-business-event']],
            ['SET @vertoad_request_id = NULL', []],
        ], $statements);
    }

    public function testAlwaysClearsMysqlSessionVariableWhenDownstreamThrows(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            },
        );
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \RuntimeException('downstream failed');
            }
        };

        try {
            (new WebhookRequestIdMiddleware($connection))->process(
                (new ServerRequestFactory())
                    ->createServerRequest('POST', '/api/v1/reviews/1/approve')
                    ->withAttribute(RequestIdContext::ATTRIBUTE, str_repeat('r', 200)),
                $handler,
            );
            self::fail('Expected downstream failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('downstream failed', $exception->getMessage());
        }

        self::assertSame(str_repeat('r', 160), $statements[0][1][0] ?? null);
        self::assertSame('SET @vertoad_request_id = NULL', $statements[1][0] ?? null);
    }

    public function testBindsNullWhenRequestIdContextHasNotBeenInitialized(): void
    {
        RequestIdContext::clear();
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 1;
            },
        );

        (new WebhookRequestIdMiddleware($connection))->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/campaigns'),
            $this->handler(),
        );

        self::assertSame(['SET @vertoad_request_id = ?', [null]], $statements[0]);
        self::assertSame(['SET @vertoad_request_id = NULL', []], $statements[1]);
    }

    public function testSkipsNonApiHeadAndNonMysqlRequests(): void
    {
        $sqlite = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $middleware = new WebhookRequestIdMiddleware($sqlite);

        self::assertSame(204, $middleware->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/health'),
            $this->handler(),
        )->getStatusCode());
        self::assertSame(204, $middleware->process(
            (new ServerRequestFactory())->createServerRequest('HEAD', '/api/v1/campaigns'),
            $this->handler(),
        )->getStatusCode());
        self::assertSame(204, $middleware->process(
            (new ServerRequestFactory())->createServerRequest('OPTIONS', '/api/v1/campaigns'),
            $this->handler(),
        )->getStatusCode());
        self::assertSame(204, $middleware->process(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/campaigns'),
            $this->handler(),
        )->getStatusCode());
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response(204);
            }
        };
    }
}
