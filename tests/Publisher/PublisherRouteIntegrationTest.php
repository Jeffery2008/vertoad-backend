<?php

declare(strict_types=1);

namespace VertoAD\Tests\Publisher;

use DateTimeImmutable;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Domain\Auth\OrganizationMembership;
use VertoAD\Http\Action\Publisher\CreatePublisherAdSlotAction;
use VertoAD\Http\Action\Publisher\CreatePublisherSiteAction;
use VertoAD\Http\Action\Publisher\GetPublisherSiteVerificationChallengeAction;
use VertoAD\Http\Action\Publisher\ListPublisherAdSlotPresetsAction;
use VertoAD\Http\Action\Publisher\ListPublisherAdSlotsAction;
use VertoAD\Http\Action\Publisher\ListPublisherSiteVerificationAttemptsAction;
use VertoAD\Http\Action\Publisher\ListPublisherSitesAction;
use VertoAD\Http\Action\Publisher\VerifyPublisherSiteAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Repository\AdSlotRepository;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepository;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepository;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\PublisherSiteVerificationService;
use VertoAD\Service\TenantAccessService;

final class PublisherRouteIntegrationTest extends TestCase
{
    public function testPublisherRoutesRequireAuthenticatedOrganizationScope(): void
    {
        $app = $this->createApp($this->createConnection());

        self::assertSame(
            'authentication_required',
            $this->handleJson($app, 'GET', '/api/v1/publisher/sites?organization_id=99')['error']['code'],
        );
        foreach (
            [
                ['POST', '/api/v1/publisher/sites?organization_id=99'],
                ['GET', '/api/v1/publisher/sites/1/verification-challenge?organization_id=99'],
                ['POST', '/api/v1/publisher/sites/1/verify?organization_id=99'],
                ['GET', '/api/v1/publisher/sites/1/verification-attempts?organization_id=99'],
                ['GET', '/api/v1/publisher/ad-slot-presets?organization_id=99'],
                ['GET', '/api/v1/publisher/sites/1/slots?organization_id=99'],
                ['POST', '/api/v1/publisher/sites/1/slots?organization_id=99'],
            ] as [$method, $uri]
        ) {
            self::assertSame(
                'authentication_required',
                $this->handleJson($app, $method, $uri, [])['error']['code'],
            );
        }
        self::assertSame(
            'organization_scope_required',
            $this->handleJson($app, 'GET', '/api/v1/publisher/sites', null, 'valid-token')['error']['code'],
        );
    }

    public function testPublisherRoutesRequireFineGrainedPublisherPermissions(): void
    {
        $connection = $this->createConnection();
        $connection->insert('sites', [
            'organization_id' => 99,
            'name' => 'Publisher Home',
            'domain' => 'example.com',
            'status' => 'verified',
            'verification_token' => 'secret',
            'verified_at' => '2026-06-08 10:00:00',
        ]);
        $app = $this->createApp($connection, []);

        foreach (
            [
                ['GET', '/api/v1/publisher/sites?organization_id=99', null, 'publisher.site.read.own'],
                ['POST', '/api/v1/publisher/sites?organization_id=99', ['name' => 'Denied', 'domain' => 'denied.example'], 'publisher.site.write.own'],
                ['GET', '/api/v1/publisher/sites/1/verification-challenge?organization_id=99', null, 'publisher.site.verify.own'],
                ['POST', '/api/v1/publisher/sites/1/verify?organization_id=99', ['method' => 'html_meta', 'observed_value' => 'x'], 'publisher.site.verify.own'],
                ['GET', '/api/v1/publisher/sites/1/verification-attempts?organization_id=99', null, 'publisher.site.verify.own'],
                ['GET', '/api/v1/publisher/ad-slot-presets?organization_id=99', null, 'publisher.slot.read.own'],
                ['GET', '/api/v1/publisher/sites/1/slots?organization_id=99', null, 'publisher.slot.read.own'],
                ['POST', '/api/v1/publisher/sites/1/slots?organization_id=99', ['name' => 'Denied', 'slot_key' => 'denied', 'size_preset' => 'leaderboard'], 'publisher.slot.write.own'],
            ] as [$method, $uri, $payload, $requiredPermission]
        ) {
            $denied = $this->handleJson($app, $method, $uri, $payload, 'valid-token');
            self::assertSame('permission_required', $denied['error']['code'] ?? null, $method . ' ' . $uri);
            self::assertSame($requiredPermission, $denied['error']['required_permission'] ?? null, $method . ' ' . $uri);
        }
    }

    public function testPublisherActionsKeepDefensiveAuthenticationAndScopeGuards(): void
    {
        $connection = $this->createConnection();
        $siteRepository = new PublisherSiteRepository($connection);
        $attemptRepository = new PublisherSiteVerificationAttemptRepository($connection);
        $slotRepository = new AdSlotRepository($connection);
        $verification = new PublisherSiteVerificationService($siteRepository);
        $slotSetup = new AdSlotSetupService($siteRepository, $slotRepository);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/direct');
        $responseFactory = new ResponseFactory();

        foreach (
            [
                static fn () => (new ListPublisherSitesAction($siteRepository))->__invoke($request, $responseFactory->createResponse()),
                static fn () => (new CreatePublisherSiteAction($siteRepository))->__invoke($request, $responseFactory->createResponse()),
                static fn () => (new GetPublisherSiteVerificationChallengeAction($siteRepository, $verification))->__invoke($request, $responseFactory->createResponse(), ['site_id' => '1']),
                static fn () => (new VerifyPublisherSiteAction($siteRepository, $verification))->__invoke($request, $responseFactory->createResponse(), ['site_id' => '1']),
                static fn () => (new ListPublisherSiteVerificationAttemptsAction($siteRepository, $attemptRepository))->__invoke($request, $responseFactory->createResponse(), ['site_id' => '1']),
                static fn () => (new ListPublisherAdSlotPresetsAction($slotSetup))->__invoke($request, $responseFactory->createResponse()),
                static fn () => (new ListPublisherAdSlotsAction($siteRepository, $slotRepository))->__invoke($request, $responseFactory->createResponse(), ['site_id' => '1']),
                static fn () => (new CreatePublisherAdSlotAction($siteRepository, $slotSetup))->__invoke($request, $responseFactory->createResponse(), ['site_id' => '1']),
            ] as $guardedResponseFactory
        ) {
            $guardedResponse = $guardedResponseFactory();
            $payload = json_decode((string) $guardedResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(401, $guardedResponse->getStatusCode());
            self::assertSame('authentication_required', $payload['code'] ?? null);
        }

        $scopeless = (new ListPublisherSitesAction($siteRepository))->__invoke(
            $request->withAttribute(
                RequestUserContext::ATTRIBUTE,
                new RequestUserContext(new AuthenticatedUser(7, 'publisher@example.com', false), null),
            ),
            (new ResponseFactory())->createResponse(),
        );
        $scopelessPayload = json_decode((string) $scopeless->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $scopeless->getStatusCode());
        self::assertSame('organization_scope_required', $scopelessPayload['code'] ?? null);
    }

    public function testCreateListVerifyAndCreateSlotThroughRoutes(): void
    {
        $connection = $this->createConnection();
        $app = $this->createApp(
            $connection,
            dnsTxtResolver: static fn (string $host): array => [
                'vertoad-site-verification=va-' . substr(hash('sha256', '1|example.com|' . (string) $connection->fetchOne('SELECT verification_token FROM sites WHERE id = 1')), 0, 40),
            ],
        );

        $created = $this->handleJson($app, 'POST', '/api/v1/publisher/sites?organization_id=99', [
            'name' => 'Publisher Home',
            'domain' => 'https://Example.COM/path',
        ], 'valid-token');

        self::assertSame(201, $created['meta']['status']);
        self::assertSame('Publisher Home', $created['data']['name']);
        self::assertSame('example.com', $created['data']['domain']);
        self::assertSame('pending', $created['data']['status']);

        $listed = $this->handleJson($app, 'GET', '/api/v1/publisher/sites?organization_id=99', null, 'valid-token');
        self::assertCount(1, $listed['data']);

        $challenge = $this->handleJson(
            $app,
            'GET',
            '/api/v1/publisher/sites/' . $created['data']['id'] . '/verification-challenge?organization_id=99&method=dns_txt',
            null,
            'valid-token',
        );
        self::assertSame('dns_txt', $challenge['data']['method']);
        self::assertSame('_vertoad.example.com', $challenge['data']['name']);

        $verified = $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $created['data']['id'] . '/verify?organization_id=99', [
            'method' => 'dns_txt',
        ], 'valid-token');
        self::assertSame('verified', $verified['data']['status']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_site_verification_attempts WHERE site_id = 1 AND organization_id = 99 AND status = "success"'));
        $attempts = $this->handleJson(
            $app,
            'GET',
            '/api/v1/publisher/sites/' . $created['data']['id'] . '/verification-attempts?organization_id=99',
            null,
            'valid-token',
        );
        self::assertCount(1, $attempts['data']);
        self::assertSame('success', $attempts['data'][0]['status']);
        self::assertSame('dns_txt', $attempts['data'][0]['method']);
        self::assertArrayNotHasKey('expected_value', $attempts['data'][0]);
        self::assertStringContainsString('sha256:', $attempts['data'][0]['observed_summary']);

        $presets = $this->handleJson($app, 'GET', '/api/v1/publisher/ad-slot-presets?organization_id=99', null, 'valid-token');
        self::assertSame(728, $presets['data']['leaderboard']['width']);

        $slot = $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $created['data']['id'] . '/slots?organization_id=99', [
            'name' => 'Article Inline',
            'slot_key' => 'article-inline',
            'size_preset' => 'leaderboard',
            'responsive' => true,
            'responsive_rules' => [
                ['min_width' => 0, 'width' => 320, 'height' => 50],
                ['min_width' => 768, 'width' => 728, 'height' => 90],
            ],
        ], 'valid-token');
        self::assertSame('leaderboard', $slot['data']['size_preset']);
        self::assertTrue($slot['data']['responsive']);

        $slots = $this->handleJson(
            $app,
            'GET',
            '/api/v1/publisher/sites/' . $created['data']['id'] . '/slots?organization_id=99',
            null,
            'valid-token',
        );
        self::assertCount(1, $slots['data']);
        self::assertSame('article-inline', $slots['data'][0]['slot_key']);

        $custom = $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $created['data']['id'] . '/slots?organization_id=99', [
            'name' => 'Custom Footer',
            'slot_key' => 'custom-footer',
            'width' => 970,
            'height' => 250,
            'responsive' => false,
        ], 'valid-token');
        self::assertNull($custom['data']['size_preset']);
        self::assertSame(970, $custom['data']['width']);
    }

    public function testPublisherRoutesRejectInvalidBodiesAndCrossOrganizationAccess(): void
    {
        $connection = $this->createConnection();
        $connection->insert('sites', [
            'organization_id' => 100,
            'name' => 'Other',
            'domain' => 'other.example',
            'status' => 'pending',
            'verification_token' => 'secret',
            'verified_at' => null,
        ]);
        $app = $this->createApp(
            $connection,
            httpFetcher: static fn (string $url): ?string => '<meta name="vertoad-site-verification" content="attacker-token">',
            dnsTxtResolver: static fn (string $host): array => [],
        );

        self::assertSame(
            'invalid_request',
            $this->handleJson($app, 'POST', '/api/v1/publisher/sites?organization_id=99', ['name' => 'x'], 'valid-token')['error']['code'],
        );
        self::assertSame(
            'publisher_site_not_found',
            $this->handleJson($app, 'GET', '/api/v1/publisher/sites/1/verification-challenge?organization_id=99', null, 'valid-token')['error']['code'],
        );
        self::assertSame(
            'publisher_site_not_found',
            $this->handleJson($app, 'GET', '/api/v1/publisher/sites/1/verification-attempts?organization_id=99', null, 'valid-token')['error']['code'],
        );

        self::assertSame(
            'invalid_request',
            $this->handleJson($app, 'GET', '/api/v1/publisher/sites/bad/verification-challenge?organization_id=99', null, 'valid-token')['error']['code'],
        );
    }

    public function testPublisherRoutesRejectBadBodiesMethodsEvidenceAndUnverifiedSlots(): void
    {
        $connection = $this->createConnection();
        $htmlBody = '<meta name="vertoad-site-verification" content="attacker-token">';
        $app = $this->createApp(
            $connection,
            httpFetcher: static function (string $url) use (&$htmlBody): ?string {
                return $htmlBody;
            },
        );

        self::assertSame(
            'invalid_request',
            $this->handleParsedBody($app, 'POST', '/api/v1/publisher/sites?organization_id=99', (object) ['name' => 'Bad'], 'valid-token')['error']['code'],
        );

        $site = $this->handleJson($app, 'POST', '/api/v1/publisher/sites?organization_id=99', [
            'name' => 'Publisher Home',
            'domain' => 'example.com',
        ], 'valid-token');

        self::assertSame(
            'invalid_request',
            $this->handleJson(
                $app,
                'GET',
                '/api/v1/publisher/sites/' . $site['data']['id'] . '/verification-challenge?organization_id=99&method=bad',
                null,
                'valid-token',
            )['error']['code'],
        );

        self::assertSame(
            'invalid_request',
            $this->handleParsedBody(
                $app,
                'POST',
                '/api/v1/publisher/sites/' . $site['data']['id'] . '/verify?organization_id=99',
                (object) ['method' => 'html_meta'],
                'valid-token',
            )['error']['code'],
        );

        self::assertSame(
            'invalid_request',
            $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $site['data']['id'] . '/verify?organization_id=99', [
                'method' => 'bad',
                'observed_value' => 'x',
            ], 'valid-token')['error']['code'],
        );

        self::assertSame(
            'publisher_site_verification_failed',
            $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $site['data']['id'] . '/verify?organization_id=99', [
                'method' => 'html_meta',
            ], 'valid-token')['error']['code'],
        );
        $failedPayload = $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $site['data']['id'] . '/verify?organization_id=99', [
            'method' => 'html_meta',
            'observed_value' => 'client-supplied-secret',
        ], 'valid-token');
        self::assertSame('publisher_site_verification_failed', $failedPayload['error']['code']);
        self::assertStringNotContainsString('client-supplied-secret', json_encode($failedPayload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('attacker-token', json_encode($failedPayload, JSON_THROW_ON_ERROR));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_site_verification_attempts WHERE site_id = 1 AND organization_id = 99 AND status = "failed"'));

        self::assertSame(
            'publisher_site_not_verified',
            $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $site['data']['id'] . '/slots?organization_id=99', [
                'name' => 'Article Inline',
                'slot_key' => 'article-inline',
                'size_preset' => 'leaderboard',
                'responsive' => false,
            ], 'valid-token')['error']['code'],
        );

        self::assertSame(
            'invalid_request',
            $this->handleParsedBody(
                $app,
                'POST',
                '/api/v1/publisher/sites/' . $site['data']['id'] . '/slots?organization_id=99',
                (object) ['name' => 'Bad'],
                'valid-token',
            )['error']['code'],
        );

        $expectedToken = 'va-' . substr(hash('sha256', '1|example.com|' . (string) $site['data']['verification_token']), 0, 40);
        $htmlBody = '<meta name="vertoad-site-verification" content="' . $expectedToken . '">';
        $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $site['data']['id'] . '/verify?organization_id=99', [
            'method' => 'html_meta',
        ], 'valid-token');

        self::assertSame(
            'invalid_request',
            $this->handleJson($app, 'POST', '/api/v1/publisher/sites/' . $site['data']['id'] . '/slots?organization_id=99', [
                'name' => 'Bad Preset',
                'slot_key' => 'bad-preset',
                'size_preset' => 'unknown',
            ], 'valid-token')['error']['code'],
        );

        self::assertSame(
            'publisher_site_not_found',
            $this->handleJson($app, 'POST', '/api/v1/publisher/sites/999/slots?organization_id=99', [
                'name' => '',
                'slot_key' => 'bad',
                'size_preset' => 'unknown',
            ], 'valid-token')['error']['code'],
        );

        self::assertSame(
            'publisher_site_not_found',
            $this->handleJson($app, 'GET', '/api/v1/publisher/sites/999/slots?organization_id=99', null, 'valid-token')['error']['code'],
        );

        self::assertSame(5, \VertoAD\Http\Action\Publisher\PublisherRequestGuards::positiveInteger(5));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(
        \Slim\App $app,
        string $method,
        string $uri,
        ?array $payload = null,
        ?string $bearerToken = null,
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['meta']['status'] = $response->getStatusCode();

        return $decoded;
    }

    private function handleParsedBody(
        \Slim\App $app,
        string $method,
        string $uri,
        mixed $payload,
        ?string $bearerToken = null,
    ): array {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withParsedBody($payload);

        if ($bearerToken !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        $response = $app->handle($request);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['meta']['status'] = $response->getStatusCode();

        return $decoded;
    }

    /**
     * @param list<string> $permissions
     */
    private function createApp(Connection $connection, array $permissions = [
        'publisher.site.read.own',
        'publisher.site.write.own',
        'publisher.site.verify.own',
        'publisher.slot.read.own',
        'publisher.slot.write.own',
    ], ?callable $httpFetcher = null, ?callable $dnsTxtResolver = null, ?callable $httpHostResolver = null): \Slim\App
    {
        $httpFetcher ??= static fn (string $url): ?string => null;
        $dnsTxtResolver ??= static fn (string $host): array => [];
        $httpHostResolver ??= static fn (string $host): array => ['93.184.216.34'];
        $container = (new ContainerBuilder())->addDefinitions([
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new class implements FirstPartySessionRepositoryInterface {
                    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
                    {
                        return $tokenHash === hash('sha256', 'valid-token')
                            ? new AuthenticatedUser(7, 'publisher@example.com', false)
                            : null;
                    }

                    public function create(int $userId, string $tokenHash, DateTimeImmutable $expiresAt): void
                    {
                    }

                    public function revoke(string $tokenHash, DateTimeImmutable $revokedAt): bool
                    {
                        return false;
                    }
                },
            BearerTokenAuthenticator::class => static fn (
                FirstPartySessionRepositoryInterface $sessions,
            ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions),
            AuthenticateRequestMiddleware::class => static fn (
                BearerTokenAuthenticator $authenticator,
            ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
            OrganizationMembershipRepositoryInterface::class => static fn (): OrganizationMembershipRepositoryInterface =>
                new PublisherPermissionMembershipRepository($permissions),
            PermissionMatcher::class => static fn (): PermissionMatcher => new PermissionMatcher(),
            TenantAccessService::class => static fn (
                OrganizationMembershipRepositoryInterface $memberships,
                PermissionMatcher $matcher,
            ): TenantAccessService => new TenantAccessService($memberships, $matcher),
            PublisherSiteRepositoryInterface::class => static fn (): PublisherSiteRepositoryInterface => new PublisherSiteRepository($connection),
            PublisherSiteVerificationAttemptRepositoryInterface::class => static fn (): PublisherSiteVerificationAttemptRepositoryInterface =>
                new PublisherSiteVerificationAttemptRepository($connection),
            PublisherSiteVerificationService::class => static fn (
                PublisherSiteRepositoryInterface $sites,
                PublisherSiteVerificationAttemptRepositoryInterface $attempts,
            ): PublisherSiteVerificationService => new PublisherSiteVerificationService(
                $sites,
                $attempts,
                $httpFetcher,
                $dnsTxtResolver,
                $httpHostResolver,
            ),
            AdSlotRepositoryInterface::class => static fn (): AdSlotRepositoryInterface => new AdSlotRepository($connection),
            AdSlotSetupService::class => static fn (
                PublisherSiteRepositoryInterface $sites,
                AdSlotRepositoryInterface $slots,
            ): AdSlotSetupService => new AdSlotSetupService($sites, $slots),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $container = $app->getContainer();
        self::assertNotNull($container);
        $require = static fn (string $permission): RequirePermissionMiddleware => new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forOrganization($permission),
        );
        $app->get('/api/v1/publisher/sites', ListPublisherSitesAction::class)
            ->add($require('publisher.site.read.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/publisher/sites', CreatePublisherSiteAction::class)
            ->add($require('publisher.site.write.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/publisher/sites/{site_id}/verification-challenge', GetPublisherSiteVerificationChallengeAction::class)
            ->add($require('publisher.site.verify.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/publisher/sites/{site_id}/verify', VerifyPublisherSiteAction::class)
            ->add($require('publisher.site.verify.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/publisher/sites/{site_id}/verification-attempts', ListPublisherSiteVerificationAttemptsAction::class)
            ->add($require('publisher.site.verify.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/publisher/ad-slot-presets', ListPublisherAdSlotPresetsAction::class)
            ->add($require('publisher.slot.read.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/publisher/sites/{site_id}/slots', ListPublisherAdSlotsAction::class)
            ->add($require('publisher.slot.read.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/publisher/sites/{site_id}/slots', CreatePublisherAdSlotAction::class)
            ->add($require('publisher.slot.write.own'))
            ->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                domain TEXT NOT NULL,
                status TEXT NOT NULL,
                verification_token TEXT NULL,
                verified_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ad_slots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                slot_key TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                size_preset TEXT NULL,
                is_responsive INTEGER NOT NULL DEFAULT 0,
                responsive_rules_json TEXT NULL,
                status TEXT NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE publisher_site_verification_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL,
                organization_id INTEGER NOT NULL,
                method TEXT NOT NULL,
                expected_value TEXT NOT NULL,
                observed_summary TEXT NULL,
                status TEXT NOT NULL,
                failure_reason TEXT NULL,
                created_at TEXT NOT NULL,
                checked_at TEXT NOT NULL
            )',
        );

        return $connection;
    }
}

final readonly class PublisherPermissionMembershipRepository implements OrganizationMembershipRepositoryInterface
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(private array $permissions)
    {
    }

    public function findActiveMembership(int $userId, int $organizationId): ?OrganizationMembership
    {
        if ($userId !== 7 || $organizationId !== 99) {
            return null;
        }

        return new OrganizationMembership($organizationId, $userId, 'active', ['publisher'], $this->permissions);
    }

    public function listForOrganization(int $organizationId): array
    {
        return [];
    }
}
