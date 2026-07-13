<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Http\Middleware\CorsMiddleware;
use VertoAD\Http\RequestIdContext;
use VertoAD\AppFactory;

final class CorsMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestIdContext::clear();
    }

    public function testSameOriginRequestPassesWithoutCorsHeaders(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            $this->request('GET', '/api/v1/auth/me'),
            $this->handler($handled, 200, ['Vary' => 'Accept-Encoding']),
        );

        self::assertTrue($handled);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    public function testAllowedConsoleOriginReceivesActualResponseHeaders(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            $this->request('GET', '/api/v1/auth/me', ['Origin' => 'https://app.example.test:443']),
            $this->handler($handled, 200, ['Vary' => 'Accept-Encoding']),
        );

        self::assertTrue($handled);
        self::assertSame('https://app.example.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('X-Request-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertSame('Accept-Encoding, Origin', $response->getHeaderLine('Vary'));
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testPublicAdsPathAllowsPublisherAndSandboxOrigins(): void
    {
        foreach (['https://publisher.example', 'null'] as $origin) {
            $handled = false;
            $response = $this->middleware()->process(
                $this->request('POST', '/api/v1/ads/track', ['Origin' => $origin]),
                $this->handler($handled),
            );

            self::assertTrue($handled);
            self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        }
    }

    public function testDisallowedOrMalformedConsoleOriginFailsClosed(): void
    {
        foreach (['https://attacker.example', 'https://app.example.test, https://attacker.example'] as $origin) {
            $handled = false;
            $response = $this->middleware()->process(
                $this->request('POST', '/api/v1/campaigns', [
                    'Origin' => $origin,
                    'X-Request-Id' => 'cors-rejected-request',
                ]),
                $this->handler($handled),
            );
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

            self::assertFalse($handled);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('cors_origin_not_allowed', $payload['error']['code']);
            self::assertSame('cors-rejected-request', $payload['request_id']);
            self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
            self::assertSame('Origin', $response->getHeaderLine('Vary'));
        }
    }

    public function testMalformedPublicOriginFailsClosed(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            $this->request('POST', '/api/v1/ads/serve', ['Origin' => 'javascript://publisher']),
            $this->handler($handled),
        );

        self::assertFalse($handled);
        self::assertSame('cors_origin_invalid', $this->payload($response)['error']['code']);
    }

    public function testValidPreflightShortCircuitsRoutingAndEchoesAllowedHeaders(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            $this->request('OPTIONS', '/api/v1/campaigns', [
                'Origin' => 'http://localhost:5173',
                'Access-Control-Request-Method' => 'PATCH',
                'Access-Control-Request-Headers' => 'Content-Type, Authorization, X-Request-Id, CF-Turnstile-Token, content-type',
            ]),
            $this->handler($handled),
        );

        self::assertFalse($handled);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost:5173', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('PATCH', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame(
            'content-type, authorization, x-request-id, cf-turnstile-token',
            $response->getHeaderLine('Access-Control-Allow-Headers'),
        );
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame(
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            $response->getHeaderLine('Vary'),
        );
    }

    public function testPreflightWithoutRequestedHeadersOmitsAllowHeaders(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            $this->request('OPTIONS', '/api/v1/campaigns', [
                'Origin' => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ]),
            $this->handler($handled),
        );

        self::assertFalse($handled);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame(
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            $response->getHeaderLine('Vary'),
        );
    }

    public function testPublicPathRejectsOriginWithOutOfRangePort(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            $this->request('GET', '/api/v1/ads/serve', [
                'Origin' => 'https://publisher.example:0',
                'X-Request-Id' => 'cors-invalid-port-request',
            ]),
            $this->handler($handled),
        );

        self::assertFalse($handled);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('cors_origin_invalid', $this->payload($response)['error']['code']);
        self::assertSame('cors-invalid-port-request', $response->getHeaderLine('X-Request-Id'));
    }

    public function testPreflightRejectsUnsupportedMethodAndHeader(): void
    {
        foreach ([
            ['method' => 'TRACE', 'headers' => 'Content-Type', 'code' => 'cors_method_not_allowed'],
            ['method' => 'POST', 'headers' => 'X-Admin-Override', 'code' => 'cors_headers_not_allowed'],
            ['method' => '', 'headers' => '', 'code' => 'cors_method_not_allowed'],
        ] as $case) {
            $handled = false;
            $response = $this->middleware()->process(
                $this->request('OPTIONS', '/api/v1/auth/login', [
                    'Origin' => 'https://app.example.test',
                    'Access-Control-Request-Method' => $case['method'],
                    'Access-Control-Request-Headers' => $case['headers'],
                ]),
                $this->handler($handled),
            );

            self::assertFalse($handled);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame($case['code'], $this->payload($response)['error']['code']);
        }
    }

    public function testConstructorRejectsUnsafeConfiguredOrigins(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CORS_ALLOWED_ORIGINS');

        new CorsMiddleware(new ResponseFactory(), ['https://app.example.test/path']);
    }

    public function testConstructorRejectsOriginsWithCredentialsOrQueryParameters(): void
    {
        foreach (['https://user@app.example.test', 'https://app.example.test?tenant=1'] as $origin) {
            try {
                new CorsMiddleware(new ResponseFactory(), [$origin]);
                self::fail('Unsafe configured origin was accepted: ' . $origin);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('CORS_ALLOWED_ORIGINS', $exception->getMessage());
            }
        }
    }

    public function testAppFactoryHandlesPreflightBeforeSlimRouting(): void
    {
        $previous = getenv('CORS_ALLOWED_ORIGINS');
        putenv('CORS_ALLOWED_ORIGINS=https://console.factory.test');
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://console.factory.test';
        $_SERVER['CORS_ALLOWED_ORIGINS'] = 'https://console.factory.test';

        try {
            $response = AppFactory::create(dirname(__DIR__))->handle($this->request(
                'OPTIONS',
                '/api/v1/auth/me',
                [
                    'Origin' => 'https://console.factory.test',
                    'Access-Control-Request-Method' => 'GET',
                    'Access-Control-Request-Headers' => 'Authorization',
                    'X-Request-Id' => 'cors-app-factory-request',
                ],
            ));

            self::assertSame(204, $response->getStatusCode());
            self::assertSame(
                'https://console.factory.test',
                $response->getHeaderLine('Access-Control-Allow-Origin'),
            );
            self::assertSame('cors-app-factory-request', $response->getHeaderLine('X-Request-Id'));
        } finally {
            if ($previous === false) {
                putenv('CORS_ALLOWED_ORIGINS');
                unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);
            } else {
                putenv('CORS_ALLOWED_ORIGINS=' . $previous);
                $_ENV['CORS_ALLOWED_ORIGINS'] = $previous;
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $previous;
            }
        }
    }

    private function middleware(): CorsMiddleware
    {
        return new CorsMiddleware(new ResponseFactory(), [
            'https://app.example.test/',
            'http://localhost:5173',
        ]);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $request = $request->withHeader($name, $value);
            }
        }

        return $request;
    }

    /** @param array<string, string> $headers */
    private function handler(bool &$handled, int $status = 200, array $headers = []): RequestHandlerInterface
    {
        return new class($handled, $status, $headers) implements RequestHandlerInterface {
            /** @param array<string, string> $headers */
            public function __construct(
                private bool &$handled,
                private readonly int $status,
                private readonly array $headers,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled = true;
                $response = (new ResponseFactory())->createResponse($this->status);
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }

                return $response;
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : [];
    }
}
