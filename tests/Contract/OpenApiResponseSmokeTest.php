<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use DateTimeImmutable;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteCollectorProxy;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OAuthAccessTokenContext;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Domain\Serving\AdCandidate;
use VertoAD\Http\Action\Auth\MeAction;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Action\HealthAction;
use VertoAD\Http\Action\OAuth\TokenAction;
use VertoAD\Http\Action\Serving\ClickAction;
use VertoAD\Http\Action\Serving\ServeAction;
use VertoAD\Http\Action\Serving\ServeFrameAction;
use VertoAD\Http\Action\Serving\TrackAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\CronAuthMiddleware;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\StaticAdCandidateRepository;
use VertoAD\Repository\Serving\StaticServingInventoryRepository;
use VertoAD\Service\Cron\CronJobRegistry;
use VertoAD\Service\Cron\NoOpCronJob;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthTokenService;
use VertoAD\Service\Serving\AdServingService;

final class OpenApiResponseSmokeTest extends TestCase
{
    public function testRepresentativeJsonSuccessResponsesUseDocumentedEnvelopeSchemas(): void
    {
        foreach (
            [
                [
                    'method' => 'GET',
                    'path' => '/api/v1/health',
                    'status' => 200,
                    'data_schema' => '#/components/schemas/HealthData',
                    'response' => $this->handle($this->healthApp(), 'GET', '/api/v1/health'),
                ],
                [
                    'method' => 'POST',
                    'path' => '/api/v1/ads/serve',
                    'status' => 200,
                    'data_schema' => '#/components/schemas/AdDecisionData',
                    'response' => $this->handle($this->servingApp([]), 'POST', '/api/v1/ads/serve', [
                        'site_id' => 10,
                        'slot_id' => 20,
                        'viewer_id' => 'viewer-1',
                    ]),
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/cron/status',
                    'status' => 200,
                    'data_schema' => '#/components/schemas/CronStatusData',
                    'response' => $this->handle(
                        $this->cronApp(),
                        'GET',
                        '/api/v1/cron/status',
                        null,
                        ['REMOTE_ADDR' => '127.0.0.1'],
                        ['X-Cron-Token' => 'test-cron-token'],
                    ),
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/auth/me',
                    'status' => 200,
                    'data_schema' => '#/components/schemas/MeData',
                    'response' => $this->handle(
                        $this->authApp(),
                        'GET',
                        '/api/v1/auth/me?organization_id=99',
                        null,
                        [],
                        ['Authorization' => 'Bearer fixed-token'],
                    ),
                ],
            ] as $case
        ) {
            $response = $case['response'];
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame($case['status'], $response->getStatusCode());
            self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
            $payload = $this->jsonPayload($response);
            $this->assertSuccessEnvelope($payload);
            $this->assertDocumentedJsonSuccessEnvelope(
                $case['method'],
                $case['path'],
                (string) $case['status'],
                $case['data_schema'],
            );
        }
    }

    public function testRepresentativeJsonErrorResponsesUseDocumentedErrorEnvelope(): void
    {
        foreach (
            [
                [
                    'method' => 'POST',
                    'path' => '/api/v1/oauth/token',
                    'status' => 400,
                    'response' => $this->handle($this->oauthTokenApp(), 'POST', '/api/v1/oauth/token'),
                    'code' => 'invalid_request',
                ],
                [
                    'method' => 'POST',
                    'path' => '/api/v1/ads/serve',
                    'status' => 422,
                    'response' => $this->handle($this->servingApp([]), 'POST', '/api/v1/ads/serve', [
                        'site_id' => 0,
                        'slot_id' => 20,
                        'viewer_id' => 'viewer-1',
                    ]),
                    'code' => 'invalid_request',
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/cron/status',
                    'status' => 401,
                    'response' => $this->handle(
                        $this->cronApp(),
                        'GET',
                        '/api/v1/cron/status',
                        null,
                        ['REMOTE_ADDR' => '127.0.0.1'],
                    ),
                    'code' => 'unauthorized',
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/auth/me',
                    'status' => 401,
                    'response' => $this->handle($this->authApp(), 'GET', '/api/v1/auth/me?organization_id=99'),
                    'code' => 'authentication_required',
                ],
            ] as $case
        ) {
            $response = $case['response'];
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame($case['status'], $response->getStatusCode());
            self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
            $payload = $this->jsonPayload($response);
            $this->assertErrorEnvelope($payload, $case['code']);
            $this->assertDocumentedJsonErrorEnvelope($case['method'], $case['path'], (string) $case['status']);
        }
    }

    public function testServeFrameHtmlSuccessIsDocumentedOutsideTheJsonEnvelope(): void
    {
        $response = $this->handle(
            $this->servingApp([$this->safeCandidate()]),
            'GET',
            '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-1&width=300&height=250',
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('<!doctype html>', (string) $response->getBody());
        $this->assertDocumentedResponseContentType('GET', '/api/v1/ads/serve', '200', 'text/html');
    }

    public function testClickRedirectSuccessIsDocumentedOutsideTheJsonEnvelope(): void
    {
        $app = $this->servingApp([$this->safeCandidate()]);
        $served = $this->jsonPayload($this->handle($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => 10,
            'slot_id' => 20,
            'viewer_id' => 'viewer-1',
        ]));

        $this->handle($app, 'POST', '/api/v1/ads/track', [
            'decision_id' => $served['data']['decision_id'],
            'viewer_id' => 'viewer-1',
            'event_id' => 'imp-1',
            'visible_ratio' => 0.5,
            'visible_ms' => 1000,
        ]);

        $response = $this->handle(
            $app,
            'GET',
            '/api/v1/ads/click?decision_id=' . rawurlencode((string) $served['data']['decision_id']) . '&viewer_id=viewer-1&event_id=clk-1',
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://advertiser.example/landing', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Location:', $this->responseBlock('GET', '/api/v1/ads/click', '302'));
        self::assertStringNotContainsString(
            PHP_EOL . '        "200":',
            $this->operationBlock('/api/v1/ads/click', 'get'),
            'GET /api/v1/ads/click must not advertise a JSON 200 success response.',
        );
    }

    public function testAdDecisionReasonEnumDocumentsAllServingNoFillReasons(): void
    {
        $schemaBlock = $this->yamlNestedBlock($this->openApi(), 4, 'AdDecisionData');
        self::assertNotNull($schemaBlock, 'AdDecisionData schema must be documented.');

        foreach ([
            'no_eligible_ad',
            'unverified_inventory',
            'unsafe_landing_url',
            'budget_insufficient_balance',
            'budget_total_cap',
            'budget_daily_cap',
            'budget_hourly_cap',
            'frequency_cap_exceeded',
            'fraud_high_risk_viewer',
            'fraud_high_risk_slot',
            'null',
        ] as $reason) {
            self::assertStringContainsString('- ' . $reason, $schemaBlock);
        }
    }

    private function healthApp(): App
    {
        return $this->app(static function (App $app): void {
            $app->get('/api/v1/health', function ($request, $response): ResponseInterface {
                return (new HealthAction())($request, $response);
            });
        });
    }

    private function oauthTokenApp(): App
    {
        return $this->app(static function (App $app): void {
            $app->post('/api/v1/oauth/token', function ($request, $response): ResponseInterface {
                return self::unconstructedTokenAction()($request, $response);
            });
        });
    }

    private static function unconstructedTokenAction(): TokenAction
    {
        $clients = new class implements OAuthClientRepositoryInterface {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function store(OAuthClient $client): OAuthClient
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function findActiveByIdentifier(string $clientIdentifier): ?OAuthClient
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function listActiveForOrganization(int $organizationId): array
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function rotateSecret(string $clientIdentifier, string $secretHash): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }
        };
        $tokens = new class implements OAuthTokenRepositoryInterface {
            public function createAuthorizationCode(OAuthClient $client, int $userId, ?int $organizationId, string $codeHash, string $redirectUri, array $scopes, string $codeChallenge, string $codeChallengeMethod, DateTimeImmutable $expiresAt): int
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function consumeAuthorizationCode(string $codeHash, DateTimeImmutable $now): ?array
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function revokeAuthorizationCode(string $codeHash, DateTimeImmutable $now): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function isAuthorizationCodeActive(string $codeHash, DateTimeImmutable $now): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function createAccessToken(OAuthClient $client, ?int $userId, ?int $organizationId, ?int $authorizationCodeId, string $accessTokenHash, array $scopes, DateTimeImmutable $expiresAt): int
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function createRefreshToken(int $accessTokenId, OAuthClient $client, ?int $userId, string $refreshTokenHash, ?int $previousRefreshTokenId, DateTimeImmutable $expiresAt): int
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function findUsableRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): ?array
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function rotateRefreshToken(int $oldRefreshTokenId, int $newRefreshTokenId, DateTimeImmutable $now): void
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function markRefreshTokenReuse(string $refreshTokenHash, DateTimeImmutable $now): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function revokeAccessToken(string $accessTokenHash, DateTimeImmutable $now): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function revokeRefreshToken(string $refreshTokenHash, DateTimeImmutable $now): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function isAccessTokenActive(string $accessTokenHash, DateTimeImmutable $now): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function findActiveUserByAccessTokenHash(string $accessTokenHash, DateTimeImmutable $now): ?AuthenticatedUser
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function findActiveAccessTokenContext(string $accessTokenHash, DateTimeImmutable $now): ?OAuthAccessTokenContext
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function cleanupExpiredTokens(DateTimeImmutable $now, int $retentionSeconds): array
            {
                throw new \LogicException('Not used by this contract smoke.');
            }
        };
        $consents = new class implements OAuthConsentRepositoryInterface {
            public function grantConsent(OAuthClient $client, int $userId, ?int $organizationId, array $scopes, DateTimeImmutable $now): void
            {
                throw new \LogicException('Not used by this contract smoke.');
            }

            public function hasConsentFor(OAuthClient $client, int $userId, ?int $organizationId, array $scopes): bool
            {
                throw new \LogicException('Not used by this contract smoke.');
            }
        };

        return new TokenAction(new OAuthTokenService($clients, $tokens, $consents, new OAuthClientSecretHasher()));
    }

    private function servingApp(array $candidates): App
    {
        return $this->app(static function (App $app) use ($candidates): void {
            $service = new AdServingService(
                new StaticServingInventoryRepository(verifiedSlots: [[10, 20]]),
                new StaticAdCandidateRepository($candidates),
                new InMemoryAdDecisionRepository(),
                new InMemoryAdEventRepository(),
            );
            $app->get('/api/v1/ads/serve', new ServeFrameAction($service));
            $app->post('/api/v1/ads/serve', new ServeAction($service));
            $app->post('/api/v1/ads/track', new TrackAction($service));
            $app->get('/api/v1/ads/click', new ClickAction($service));
        });
    }

    private function cronApp(): App
    {
        $settings = [
            'cron' => [
                'token' => 'test-cron-token',
                'allowed_ips' => ['127.0.0.1'],
                'jobs' => ['redis-events-consume'],
            ],
        ];

        return $this->app(static function (App $app) use ($settings): void {
            $registry = new CronJobRegistry([new NoOpCronJob('redis-events-consume')]);
            $app->group('/api/v1/cron', function (RouteCollectorProxy $group) use ($settings, $registry): void {
                $group->get('/status', new CronStatusAction($settings, $registry));
            })->add(new CronAuthMiddleware($app->getResponseFactory(), $settings));
        });
    }

    private function authApp(): App
    {
        return $this->app(static function (App $app): void {
            $sessions = new class implements FirstPartySessionRepositoryInterface {
                public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
                {
                }

                public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
                {
                    return hash_equals(hash('sha256', 'fixed-token'), $tokenHash)
                        ? new AuthenticatedUser(7, 'owner@example.test', false)
                        : null;
                }

                public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
                {
                    return false;
                }
            };
            $memberships = new class implements OrganizationMembershipRepositoryInterface {
                public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
                {
                    return new OrganizationMembership($organizationId, $userId, 'active', ['advertiser-owner'], ['campaigns.manage']);
                }

                public function listForOrganization(int $organizationId): array
                {
                    return [];
                }
            };
            $app->get('/api/v1/auth/me', new MeAction($memberships))
                ->add(new AuthenticateRequestMiddleware(new BearerTokenAuthenticator($sessions)));
        });
    }

    private function app(callable $routes): App
    {
        SlimAppFactory::setContainer((new ContainerBuilder())->build());
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $routes($app);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string> $serverParams
     * @param array<string, string> $headers
     */
    private function handle(
        App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        array $serverParams = [],
        array $headers = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, $serverParams);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function jsonPayload(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function assertSuccessEnvelope(array $payload): void
    {
        foreach (['data', 'error', 'meta', 'request_id'] as $field) {
            self::assertArrayHasKey($field, $payload);
        }

        self::assertIsArray($payload['data']);
        self::assertNull($payload['error']);
        self::assertIsArray($payload['meta']);
        self::assertNotSame('', $payload['request_id']);
    }

    /** @param array<string, mixed> $payload */
    private function assertErrorEnvelope(array $payload, string $expectedCode): void
    {
        foreach (['data', 'error', 'meta', 'request_id'] as $field) {
            self::assertArrayHasKey($field, $payload);
        }

        self::assertNull($payload['data']);
        self::assertIsArray($payload['error']);
        self::assertSame($expectedCode, $payload['error']['code'] ?? null);
        self::assertIsArray($payload['meta']);
        self::assertNotSame('', $payload['request_id']);
    }

    private function assertDocumentedJsonSuccessEnvelope(string $method, string $path, string $status, string $dataSchemaRef): void
    {
        $this->assertDocumentedResponseContentType($method, $path, $status, 'application/json');
        $responseBlock = $this->responseBlock($method, $path, $status);

        self::assertStringContainsString('$ref: "#/components/schemas/SuccessEnvelope"', $responseBlock);
        self::assertStringContainsString('data:', $responseBlock);
        self::assertStringContainsString('$ref: "' . $dataSchemaRef . '"', $responseBlock);
    }

    private function assertDocumentedJsonErrorEnvelope(string $method, string $path, string $status): void
    {
        $this->assertDocumentedResponseContentType($method, $path, $status, 'application/json');
        self::assertStringContainsString(
            '$ref: "#/components/schemas/ErrorEnvelope"',
            $this->yamlNestedBlock($this->openApi(), 4, 'Error') ?? '',
        );
    }

    private function assertDocumentedResponseContentType(string $method, string $path, string $status, string $contentType): void
    {
        self::assertStringContainsString($contentType . ':', $this->responseBlock($method, $path, $status));
    }

    private function responseBlock(string $method, string $path, string $status): string
    {
        $operationBlock = $this->operationBlock($path, strtolower($method));
        $responseBlock = $this->yamlNestedBlock($operationBlock, 8, $status);
        self::assertNotNull($responseBlock, "{$method} {$path} response {$status} must be documented.");

        if (preg_match('/^\s{10}\$ref:\s*[\'"]?#\/components\/responses\/([^\'"\s]+)[\'"]?\s*$/m', $responseBlock, $refMatch) === 1) {
            $resolved = $this->yamlNestedBlock($this->openApi(), 4, $refMatch[1]);
            self::assertNotNull($resolved, "Referenced response {$refMatch[1]} must exist.");

            return $resolved;
        }

        return $responseBlock;
    }

    private function operationBlock(string $path, string $method): string
    {
        $pathBlock = $this->yamlNestedBlock($this->openApi(), 2, $path);
        self::assertNotNull($pathBlock, "{$path} must be documented.");
        $operationBlock = $this->yamlNestedBlock($pathBlock, 4, $method);
        self::assertNotNull($operationBlock, strtoupper($method) . " {$path} must be documented.");

        return $operationBlock;
    }

    private function yamlNestedBlock(string $contents, int $indent, string $key): ?string
    {
        $lines = preg_split('/\R/', $contents);
        if ($lines === false) {
            return null;
        }

        $block = [];
        $capturing = false;
        $quotedKey = preg_quote($key, '/');
        $keyPattern = '/^\s{' . $indent . '}"?' . $quotedKey . '"?:\s*(?:\S.*)?$/';
        $nextPeerPattern = '/^\s{' . $indent . '}\S/';

        foreach ($lines as $line) {
            if (!$capturing && preg_match($keyPattern, $line) === 1) {
                $capturing = true;
                $block[] = $line;
                continue;
            }

            if ($capturing) {
                if (preg_match($nextPeerPattern, $line) === 1) {
                    break;
                }

                $block[] = $line;
            }
        }

        return $capturing ? implode(PHP_EOL, $block) : null;
    }

    private function openApi(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    }

    private function safeCandidate(): AdCandidate
    {
        return new AdCandidate(
            adId: 'ad-1',
            campaignId: 30,
            advertiserOrganizationId: 40,
            creativeHtml: '<strong>VertoAD creative</strong>',
            landingUrl: 'https://advertiser.example/landing',
            width: 300,
            height: 250,
            impressionCostPoints: 10,
            clickCostPoints: 20,
            assetType: 'image',
            assetObjectKey: 'organizations/40/assets/creative.png',
            assetContentType: 'image/png',
        );
    }
}
