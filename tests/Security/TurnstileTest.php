<?php

declare(strict_types=1);

namespace VertoAD\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\TurnstileMiddleware;
use VertoAD\Infrastructure\Security\TurnstileVerifier;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class TurnstileTest extends TestCase
{
    public function testVerifierRejectsMissingTokenWithoutCallingProvider(): void
    {
        $verifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static function (): array {
                TestCase::fail('Provider should not be called when the token is missing.');
            },
        );

        $result = $verifier->verify(' ', '203.0.113.10');

        self::assertFalse($result->success);
        self::assertSame('turnstile_token_required', $result->code);
        self::assertFalse($result->providerAvailable);
    }

    public function testVerifierReturnsProviderFailureWithoutLeakingSecret(): void
    {
        $verifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static fn (): array => throw new \RuntimeException('secret-value network failure'),
        );

        $result = $verifier->verify('token-value', '203.0.113.10');

        self::assertFalse($result->success);
        self::assertSame('turnstile_provider_unavailable', $result->code);
        self::assertFalse($result->providerAvailable);
        self::assertStringNotContainsString('secret-value', $result->message);
    }

    public function testVerifierMapsUnsuccessfulAndSuccessfulProviderResponses(): void
    {
        $captured = null;
        $verifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static function (string $url, array $form) use (&$captured): array {
                $captured = [$url, $form];

                return ['success' => false, 'error-codes' => ['timeout-or-duplicate']];
            },
        );

        $failure = $verifier->verify('bad-token', '203.0.113.10');

        self::assertSame(['https://turnstile.example/verify', [
            'secret' => 'secret-value',
            'response' => 'bad-token',
            'remoteip' => '203.0.113.10',
        ]], $captured);
        self::assertFalse($failure->success);
        self::assertSame('turnstile_verification_failed', $failure->code);
        self::assertSame(['timeout-or-duplicate'], $failure->errorCodes);

        $successVerifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static fn (): array => ['success' => true, 'challenge_ts' => '2026-06-08T00:00:00Z'],
        );

        self::assertTrue($successVerifier->verify('good-token', null)->success);
    }

    public function testVerifierAllowsRequestsWhenSecretIsNotConfigured(): void
    {
        $verifier = new TurnstileVerifier(
            secretKey: ' ',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static function (): array {
                TestCase::fail('Provider should not be called when Turnstile is not configured.');
            },
        );

        $result = $verifier->verify('token-value', '203.0.113.10');

        self::assertTrue($result->success);
        self::assertSame('turnstile_not_configured', $result->code);
        self::assertFalse($result->providerAvailable);
    }

    public function testVerifierTreatsInvalidTransportResponsesAsProviderUnavailable(): void
    {
        $verifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static fn (): string => 'not-an-array',
        );

        $result = $verifier->verify('token-value', '203.0.113.10');

        self::assertFalse($result->success);
        self::assertSame('turnstile_provider_unavailable', $result->code);
        self::assertFalse($result->providerAvailable);
    }

    public function testVerifierUsesDefaultTransportAndOmitsBlankRemoteIp(): void
    {
        $verifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'data://text/plain,%7B%22success%22%3Atrue%7D',
        );

        $result = $verifier->verify('token-value', '   ');

        self::assertTrue($result->success);
        self::assertSame('turnstile_verified', $result->code);
        self::assertTrue($result->providerAvailable);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unavailableDefaultTransportProvider(): iterable
    {
        yield 'unreadable provider response' => ['file:///definitely/not/a/turnstile-response.json'];
        yield 'invalid provider JSON' => ['data://text/plain,%7Bnot-json'];
        yield 'non-object provider JSON' => ['data://text/plain,true'];
    }

    #[DataProvider('unavailableDefaultTransportProvider')]
    public function testVerifierTreatsDefaultTransportFailuresAsProviderUnavailable(string $verifyUrl): void
    {
        $verifier = new TurnstileVerifier('secret-value', $verifyUrl);

        $result = $verifier->verify('token-value', '203.0.113.10');

        self::assertFalse($result->success);
        self::assertSame('turnstile_provider_unavailable', $result->code);
        self::assertFalse($result->providerAvailable);
    }

    public function testVerifierRejectsInvalidTimeout(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Turnstile timeout must be positive.');

        new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', timeoutSeconds: 0);
    }

    public function testVerifierIgnoresMalformedProviderErrorCodes(): void
    {
        $verifier = new TurnstileVerifier(
            secretKey: 'secret-value',
            verifyUrl: 'https://turnstile.example/verify',
            transport: static fn (): array => ['success' => false, 'error-codes' => 'bad-input-response'],
        );

        $result = $verifier->verify('bad-token', '203.0.113.10');

        self::assertFalse($result->success);
        self::assertSame('turnstile_verification_failed', $result->code);
        self::assertSame([], $result->errorCodes);
    }

    public function testMiddlewareDeniesMissingTokenWithEnvelopeRequestIdAndAuditEvent(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static fn (): array => ['success' => true]),
            new AuditLogService($auditRepository),
        );

        $response = $this->handleJson($app, [], 'turnstile-request-1');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertSame('turnstile_token_required', $payload['error']['code']);
        self::assertSame(['api_version' => 'v1'], $payload['meta']);
        self::assertSame('turnstile-request-1', $payload['request_id']);
        self::assertSame('turnstile-request-1', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('security.turnstile.denied', $auditRepository->entries[0]->action ?? null);
    }

    public function testMiddlewareSkipsVerificationWhenTurnstileIsNotConfigured(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier(' ', 'https://turnstile.example/verify', static function (): array {
                TestCase::fail('Provider should not be called when Turnstile is not configured.');
            }),
            new AuditLogService($auditRepository),
        );

        $response = $this->handleJson($app, [], 'turnstile-request-unconfigured');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame([], $auditRepository->entries);
    }

    public function testMiddlewareFailsClosedWhenTurnstileSecretIsMissingOutsideLocalTesting(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier(' ', 'https://turnstile.example/verify', static function (): array {
                TestCase::fail('Provider should not be called when Turnstile is unconfigured.');
            }, allowUnconfiguredSuccess: false),
            new AuditLogService($auditRepository),
            new TurnstilePolicy(
                enabled: true,
                timeoutSeconds: 5,
                protectedEndpoints: ['POST:/protected'],
            ),
            allowRuntimeBypass: false,
        );

        $response = $this->handleJson($app, ['cf_turnstile_token' => 'token-value'], 'turnstile-unconfigured-prod');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertSame('turnstile_not_configured', $payload['error']['code']);
        self::assertSame('turnstile-unconfigured-prod', $payload['request_id']);
        self::assertSame('turnstile-unconfigured-prod', $response->getHeaderLine('X-Request-Id'));
        self::assertSame('security.turnstile.denied', $auditRepository->entries[0]->action ?? null);
    }

    public function testMiddlewareAllowsSuccessAndAuditsAcceptedEvent(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static fn (): array => ['success' => true]),
            new AuditLogService($auditRepository),
        );

        $response = $this->handleJson($app, ['cf_turnstile_token' => 'good-token'], 'turnstile-request-2');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('security.turnstile.accepted', $auditRepository->entries[0]->action ?? null);
    }

    public function testMiddlewareReadsTokenFromHeaderBeforeBody(): void
    {
        $capturedToken = null;
        $app = $this->createProtectedApp(
            new TurnstileVerifier(
                'secret-value',
                'https://turnstile.example/verify',
                static function (string $url, array $form) use (&$capturedToken): array {
                    $capturedToken = $form['response'];

                    return ['success' => true];
                },
            ),
            new AuditLogService(new CapturingAuditLogRepository()),
        );

        $response = $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/protected', ['REMOTE_ADDR' => '203.0.113.10'])
            ->withParsedBody(['cf_turnstile_token' => 'body-token'])
            ->withHeader('CF-Turnstile-Token', ' header-token ')
            ->withHeader('X-Request-Id', 'turnstile-header-token'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('header-token', $capturedToken);
    }

    public function testMiddlewareReturnsMissingTokenWhenParsedBodyIsNotAnArray(): void
    {
        $app = $this->createProtectedApp(
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static fn (): array => ['success' => true]),
            new AuditLogService(new CapturingAuditLogRepository()),
        );

        $response = $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/protected', ['REMOTE_ADDR' => '203.0.113.10'])
            ->withParsedBody((object) ['cf_turnstile_token' => 'object-token'])
            ->withHeader('X-Request-Id', 'turnstile-non-array-body'));
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertSame('turnstile_token_required', $payload['error']['code']);
        self::assertSame('turnstile-non-array-body', $payload['request_id']);
    }

    public function testMiddlewareReturnsServiceUnavailableWhenProviderCannotBeReached(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier(
                'secret-value',
                'https://turnstile.example/verify',
                static fn (): array => throw new \RuntimeException('network unavailable'),
            ),
            new AuditLogService($auditRepository),
        );

        $response = $this->handleJson($app, ['cf_turnstile_token' => 'token-value'], 'turnstile-provider-down');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertSame('turnstile_provider_unavailable', $payload['error']['code']);
        self::assertSame('turnstile-provider-down', $payload['request_id']);
        self::assertSame('security.turnstile.denied', $auditRepository->entries[0]->action ?? null);
    }

    public function testMiddlewareSkipsVerificationWhenEndpointIsNotProtectedByPolicy(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static function (): array {
                TestCase::fail('Provider should not be called for endpoints outside the Turnstile policy.');
            }),
            new AuditLogService($auditRepository),
            new TurnstilePolicy(
                enabled: true,
                timeoutSeconds: 5,
                protectedEndpoints: ['POST:/api/v1/auth/login'],
            ),
        );

        $response = $this->handleJson($app, [], 'turnstile-unprotected-endpoint');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame([], $auditRepository->entries);
    }

    public function testPolicyProtectsServingEndpointsOnlyWhenTheyAreRiskFlagged(): void
    {
        $policy = new TurnstilePolicy(
            enabled: true,
            timeoutSeconds: 5,
            protectedEndpoints: ['POST:/api/v1/auth/login'],
            conditionalProtectedEndpoints: [
                'POST:/api/v1/ads/track',
                'GET:/api/v1/ads/click',
            ],
        );

        self::assertTrue($policy->protects('POST', '/api/v1/auth/login'));
        self::assertFalse($policy->protects('POST', '/api/v1/ads/track'));
        self::assertFalse($policy->protects('GET', '/api/v1/ads/click'));
        self::assertTrue($policy->conditionallyProtects('POST', '/api/v1/ads/track'));
        self::assertTrue($policy->conditionallyProtects('get', '/api/v1/ads/click'));
        self::assertFalse($policy->conditionallyProtects('POST', '/api/v1/auth/login'));
    }

    public function testPolicyDisabledAndWildcardRuntimeBehavior(): void
    {
        $disabled = new TurnstilePolicy(
            enabled: false,
            timeoutSeconds: 5,
            protectedEndpoints: ['POST:/protected'],
            conditionalProtectedEndpoints: ['POST:/api/v1/ads/track'],
        );

        self::assertFalse($disabled->protectsRequest('POST', '/protected', abnormalTraffic: true));
        self::assertFalse($disabled->conditionallyProtects('POST', '/api/v1/ads/track'));

        $wildcard = new TurnstilePolicy(
            enabled: true,
            timeoutSeconds: 5,
            protectedEndpoints: ['*'],
        );

        self::assertTrue($wildcard->matchesRequest('delete', '/any/private/path', abnormalTraffic: false));
    }

    public function testPolicyRejectsEmptyProtectedEndpointsInConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('protected_endpoints must not be empty.');

        new TurnstilePolicy(
            enabled: true,
            timeoutSeconds: 5,
            protectedEndpoints: [],
        );
    }

    public function testMiddlewareSkipsVerificationWhenPolicyIsDisabled(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static function (): array {
                TestCase::fail('Provider should not be called when the Turnstile policy is disabled.');
            }),
            new AuditLogService($auditRepository),
            new TurnstilePolicy(
                enabled: false,
                timeoutSeconds: 5,
                protectedEndpoints: ['POST:/protected'],
            ),
        );

        $response = $this->handleJson($app, [], 'turnstile-disabled-policy');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame([], $auditRepository->entries);
    }

    public function testMiddlewareFailsClosedWhenPolicyIsDisabledOutsideLocalTesting(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = $this->createProtectedApp(
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static function (): array {
                TestCase::fail('Provider should not be called when the Turnstile policy is disabled.');
            }),
            new AuditLogService($auditRepository),
            new TurnstilePolicy(
                enabled: false,
                timeoutSeconds: 5,
                protectedEndpoints: ['POST:/protected'],
            ),
            allowRuntimeBypass: false,
        );

        $response = $this->handleJson($app, ['cf_turnstile_token' => 'token-value'], 'turnstile-disabled-prod');
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertNull($payload['data']);
        self::assertSame('turnstile_policy_disabled', $payload['error']['code']);
        self::assertSame('turnstile-disabled-prod', $payload['request_id']);
        self::assertSame('security.turnstile.denied', $auditRepository->entries[0]->action ?? null);
    }

    public function testMiddlewareDoesNotFailClosedForUnflaggedConditionalEndpointWhenPolicyIsDisabledOutsideLocalTesting(): void
    {
        $auditRepository = new CapturingAuditLogRepository();
        $app = new App(new ResponseFactory());
        $responseFactory = $app->getResponseFactory();
        $policy = new TurnstilePolicy(
            enabled: false,
            timeoutSeconds: 5,
            protectedEndpoints: ['POST:/api/v1/auth/login'],
            conditionalProtectedEndpoints: ['POST:/api/v1/ads/track'],
        );
        $app->post('/api/v1/ads/track', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->add(new TurnstileMiddleware(
            $responseFactory,
            new TurnstileVerifier('secret-value', 'https://turnstile.example/verify', static function (): array {
                TestCase::fail('Provider should not be called for unflagged conditional traffic.');
            }),
            new AuditLogService($auditRepository),
            policy: $policy,
            allowRuntimeBypass: false,
        ));
        $app->add(new ApiEnvelopeMiddleware($responseFactory));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        $normalResponse = $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/ads/track')
            ->withHeader('X-Request-Id', 'turnstile-disabled-normal-conditional'));
        $normalPayload = json_decode((string) $normalResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $normalResponse->getStatusCode());
        self::assertSame('ok', $normalPayload['data']['status']);
        self::assertSame([], $auditRepository->entries);

        $riskResponse = $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/ads/track?risk=high')
            ->withHeader('X-Request-Id', 'turnstile-disabled-risk-conditional'));
        $riskPayload = json_decode((string) $riskResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $riskResponse->getStatusCode());
        self::assertNull($riskPayload['data']);
        self::assertSame('turnstile_policy_disabled', $riskPayload['error']['code']);
        self::assertSame('turnstile-disabled-risk-conditional', $riskPayload['request_id']);
        self::assertSame('security.turnstile.denied', $auditRepository->entries[0]->action ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleJson(App $app, array $payload, string $requestId): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())
            ->createServerRequest('POST', '/protected', ['REMOTE_ADDR' => '203.0.113.10'])
            ->withParsedBody($payload)
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('User-Agent', 'SecurityTest/1.0'));
    }

    private function createProtectedApp(
        TurnstileVerifier $verifier,
        AuditLogService $audit,
        ?TurnstilePolicy $policy = null,
        bool $allowRuntimeBypass = true,
    ): App
    {
        $app = new App(new ResponseFactory());
        $responseFactory = $app->getResponseFactory();
        $app->post('/protected', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write(json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        })->add(new TurnstileMiddleware(
            $responseFactory,
            $verifier,
            $audit,
            policy: $policy ?? new TurnstilePolicy(
                enabled: true,
                timeoutSeconds: 5,
                protectedEndpoints: ['POST:/protected'],
            ),
            allowRuntimeBypass: $allowRuntimeBypass,
        ));
        $app->add(new ApiEnvelopeMiddleware($responseFactory));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }
}

final class CapturingAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
