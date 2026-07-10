<?php

declare(strict_types=1);

namespace VertoAD\Tests\Campaigns;

use DateTimeImmutable;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Auth\AuthenticatedUser;
use VertoAD\Http\Action\Campaigns\CreateCampaignAction;
use VertoAD\Http\Action\Campaigns\GetCampaignAction;
use VertoAD\Http\Action\Campaigns\ListCampaignsAction;
use VertoAD\Http\Action\Campaigns\UpdateCampaignAction;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Repository\Campaign\CampaignRepository;
use VertoAD\Repository\Campaign\CampaignRepositoryInterface;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Service\Campaign\CampaignService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

final class CampaignRouteIntegrationTest extends TestCase
{
    public function testCampaignRoutesRequireAuthenticatedOrganizationScope(): void
    {
        $app = $this->createApp($this->createConnection());

        $unauthenticated = $this->handleJson($app, 'GET', '/api/v1/campaigns?organization_id=99');
        self::assertSame('authentication_required', $unauthenticated['error']['code']);

        $missingScope = $this->handleJson($app, 'GET', '/api/v1/campaigns', null, 'valid-token');
        self::assertSame('organization_scope_required', $missingScope['error']['code']);

        $badScope = $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=0', [], 'valid-token');
        self::assertSame('organization_scope_required', $badScope['error']['code']);
    }

    public function testCreateListGetUpdateAndActivateCampaignThroughRoutes(): void
    {
        $connection = $this->createConnection();
        $this->insertApprovedAsset($connection, 1, 99);
        $app = $this->createApp($connection);

        $created = $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=99', $this->validPayload(), 'valid-token');

        self::assertSame('v1', $created['meta']['api_version']);
        self::assertSame('draft', $created['data']['status']);
        self::assertArrayHasKey('pause_reason', $created['data']);
        self::assertNull($created['data']['pause_reason']);
        self::assertSame('cpm', $created['data']['pricing_model']);
        self::assertSame(120, $created['data']['bid_points']);
        self::assertSame(['desktop', 'mobile'], $created['data']['targeting']['devices']);
        self::assertSame([
            'day_of_week' => 1,
            'start' => '09:00',
            'end' => '18:00',
            'timezone' => 'Asia/Shanghai',
        ], $created['data']['targeting']['time_windows'][0]);
        self::assertSame(
            $created['data']['targeting'],
            json_decode((string) $connection->fetchOne(
                'SELECT targeting_json FROM campaigns WHERE id = ?',
                [$created['data']['id']],
            ), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(10_000, $created['data']['budget']['total_cap_points']);
        self::assertSame(10_000, (int) $connection->fetchOne('SELECT total_cap_points FROM campaign_budget_caps WHERE campaign_id = ?', [$created['data']['id']]));

        $listed = $this->handleJson($app, 'GET', '/api/v1/campaigns?organization_id=99', null, 'valid-token');
        self::assertCount(1, $listed['data']);
        self::assertSame($created['data']['id'], $listed['data'][0]['id']);
        self::assertNull($listed['data'][0]['pause_reason']);

        $fetched = $this->handleJson($app, 'GET', '/api/v1/campaigns/' . $created['data']['id'] . '?organization_id=99', null, 'valid-token');
        self::assertSame('Launch campaign', $fetched['data']['name']);
        self::assertNull($fetched['data']['pause_reason']);

        $updated = $this->handleJson($app, 'PATCH', '/api/v1/campaigns/' . $created['data']['id'] . '?organization_id=99', [
            'name' => 'Launch campaign updated',
            'pricing_model' => 'cpc',
            'bid_points' => 45,
            'status' => 'active',
            'budget' => [
                'total_cap_points' => 9_000,
                'daily_cap_points' => 1_500,
                'hourly_cap_points' => 200,
            ],
        ], 'valid-token');

        self::assertSame('active', $updated['data']['status']);
        self::assertSame('cpc', $updated['data']['pricing_model']);
        self::assertSame(45, $updated['data']['bid_points']);
        self::assertSame(9_000, $updated['data']['budget']['total_cap_points']);
        self::assertNull($updated['data']['pause_reason']);

        self::assertTrue((new CampaignRepository($connection))->pauseIfActive(
            99,
            (int) $created['data']['id'],
            'total_cap_exhausted',
        ));
        $autoPaused = $this->handleJson($app, 'GET', '/api/v1/campaigns/' . $created['data']['id'] . '?organization_id=99', null, 'valid-token');
        self::assertSame('paused', $autoPaused['data']['status']);
        self::assertSame('total_cap_exhausted', $autoPaused['data']['pause_reason']);
    }

    public function testActivationRequiresApprovedCreativeReview(): void
    {
        $connection = $this->createConnection();
        $this->insertPendingAsset($connection, 1, 99);
        $app = $this->createApp($connection);

        $campaign = $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=99', $this->validPayload(), 'valid-token');
        $activation = $this->handleJson($app, 'PATCH', '/api/v1/campaigns/' . $campaign['data']['id'] . '?organization_id=99', [
            'status' => 'active',
        ], 'valid-token');

        self::assertSame('campaign_creative_not_approved', $activation['error']['code']);
    }

    public function testValidationErrorsAreStableForUnsafeUrlsScheduleBudgetAndTenantLookup(): void
    {
        $connection = $this->createConnection();
        $this->insertApprovedAsset($connection, 1, 99);
        $app = $this->createApp($connection);

        $unsafeUrl = $this->validPayload();
        $unsafeUrl['landing_url'] = 'javascript:alert(1)';
        self::assertSame(
            'campaign_landing_url_invalid',
            $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=99', $unsafeUrl, 'valid-token')['error']['code'],
        );

        $badSchedule = $this->validPayload();
        $badSchedule['schedule'] = ['starts_at' => '2026-06-09T00:00:00+00:00', 'ends_at' => '2026-06-08T00:00:00+00:00'];
        self::assertSame(
            'campaign_schedule_invalid',
            $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=99', $badSchedule, 'valid-token')['error']['code'],
        );

        $badBudget = $this->validPayload();
        $badBudget['budget'] = ['daily_cap_points' => 100, 'hourly_cap_points' => 150];
        self::assertSame(
            'campaign_budget_invalid',
            $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=99', $badBudget, 'valid-token')['error']['code'],
        );

        $missing = $this->handleJson($app, 'GET', '/api/v1/campaigns/999?organization_id=99', null, 'valid-token');
        self::assertSame('campaign_not_found', $missing['error']['code']);

        $wrongOrg = $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=100', $this->validPayload(), 'valid-token');
        self::assertSame('campaign_creative_not_found', $wrongOrg['error']['code']);
    }

    public function testRoutesReturnInvalidRequestForBadBodiesAndRouteIds(): void
    {
        $connection = $this->createConnection();
        $this->insertApprovedAsset($connection, 1, 99);
        $app = $this->createApp($connection);

        $badCreateBody = $this->handleParsedBody($app, 'POST', '/api/v1/campaigns?organization_id=99', (object) ['bad' => true], 'valid-token');
        self::assertSame('invalid_request', $badCreateBody['error']['code']);

        $unauthenticatedGet = $this->handleJson($app, 'GET', '/api/v1/campaigns/1?organization_id=99');
        self::assertSame('authentication_required', $unauthenticatedGet['error']['code']);

        $badGetId = $this->handleJson($app, 'GET', '/api/v1/campaigns/abc?organization_id=99', null, 'valid-token');
        self::assertSame('invalid_request', $badGetId['error']['code']);

        $unauthenticatedUpdate = $this->handleJson($app, 'PATCH', '/api/v1/campaigns/1?organization_id=99', []);
        self::assertSame('authentication_required', $unauthenticatedUpdate['error']['code']);

        $badUpdateId = $this->handleJson($app, 'PATCH', '/api/v1/campaigns/abc?organization_id=99', [], 'valid-token');
        self::assertSame('invalid_request', $badUpdateId['error']['code']);

        $created = $this->handleJson($app, 'POST', '/api/v1/campaigns?organization_id=99', $this->validPayload(), 'valid-token');
        $badUpdateBody = $this->handleParsedBody(
            $app,
            'PATCH',
            '/api/v1/campaigns/' . $created['data']['id'] . '?organization_id=99',
            (object) ['bad' => true],
            'valid-token',
        );
        self::assertSame('invalid_request', $badUpdateBody['error']['code']);
    }

    public function testCampaignRouteGuardAcceptsPositiveIntegerValues(): void
    {
        self::assertSame(5, \VertoAD\Http\Action\Campaigns\CampaignRequestGuards::positiveInteger(5));
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

        $decoded = json_decode((string) $app->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
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

        $decoded = json_decode((string) $app->handle($request)->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createApp(Connection $connection): \Slim\App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            FirstPartySessionRepositoryInterface::class => static fn (): FirstPartySessionRepositoryInterface =>
                new class implements FirstPartySessionRepositoryInterface {
                    public function findActiveUserByTokenHash(string $tokenHash, DateTimeImmutable $now): ?AuthenticatedUser
                    {
                        return $tokenHash === hash('sha256', 'valid-token')
                            ? new AuthenticatedUser(7, 'campaigns@example.com', false)
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
            CampaignRepositoryInterface::class => static fn (): CampaignRepositoryInterface => new CampaignRepository($connection),
            ReviewRepositoryInterface::class => static fn (): ReviewRepositoryInterface => new ReviewRepository($connection),
            CampaignBudgetRepositoryInterface::class => static fn (): CampaignBudgetRepositoryInterface => new CampaignBudgetRepository($connection),
            PointsLedgerRepositoryInterface::class => static fn (): PointsLedgerRepositoryInterface => new PointsLedgerRepository($connection),
            PointsLedgerService::class => static fn (
                PointsLedgerRepositoryInterface $repository,
            ): PointsLedgerService => new PointsLedgerService($repository),
            CampaignBudgetService::class => static fn (
                CampaignBudgetRepositoryInterface $budgets,
                PointsLedgerService $ledger,
                PointsLedgerRepositoryInterface $ledgerRepository,
                CampaignRepositoryInterface $campaigns,
            ): CampaignBudgetService => new CampaignBudgetService($budgets, $ledger, $ledgerRepository, $campaigns),
            CampaignService::class => static fn (
                CampaignRepositoryInterface $campaigns,
                ReviewRepositoryInterface $reviews,
                CampaignBudgetService $budgets,
            ): CampaignService => new CampaignService($campaigns, $reviews, $budgets),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->get('/api/v1/campaigns', ListCampaignsAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->post('/api/v1/campaigns', CreateCampaignAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->get('/api/v1/campaigns/{campaign_id}', GetCampaignAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->patch('/api/v1/campaigns/{campaign_id}', UpdateCampaignAction::class)->add(AuthenticateRequestMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CampaignSchema::create($connection);
        $connection->executeStatement(
            'CREATE TABLE ledger_account_balances (
                organization_id INTEGER NOT NULL,
                account_type VARCHAR(64) NOT NULL,
                balance_points INTEGER NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (organization_id, account_type)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                account_type VARCHAR(64) NOT NULL,
                account_id INTEGER NULL,
                points_amount INTEGER NOT NULL,
                direction VARCHAR(16) NOT NULL,
                balance_after_points INTEGER NULL,
                reference_type VARCHAR(120) NULL,
                reference_id INTEGER NULL,
                idempotency_key VARCHAR(160) NOT NULL UNIQUE,
                memo VARCHAR(255) NULL,
                metadata_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Launch campaign',
            'pricing_model' => 'cpm',
            'bid_points' => 120,
            'landing_url' => 'https://landing.example/path',
            'creative_asset_id' => 1,
            'schedule' => [
                'starts_at' => '2026-06-08T00:00:00+00:00',
                'ends_at' => '2026-07-08T00:00:00+00:00',
            ],
            'targeting' => [
                'devices' => ['mobile', 'desktop', 'mobile'],
                'geos' => ['CN-SH'],
                'site_ids' => [10],
                'slot_ids' => [20],
                'time_windows' => [[
                    'day_of_week' => 1,
                    'start' => '09:00',
                    'end' => '18:00',
                    'timezone' => 'asia/shanghai',
                ]],
            ],
            'budget' => [
                'total_cap_points' => 10_000,
                'daily_cap_points' => 2_000,
                'hourly_cap_points' => 500,
            ],
        ];
    }

    private function insertApprovedAsset(Connection $connection, int $assetId, int $organizationId): void
    {
        $this->insertPendingAsset($connection, $assetId, $organizationId);
        $connection->insert('creative_reviews', [
            'asset_id' => $assetId,
            'organization_id' => $organizationId,
            'status' => 'approved',
            'requested_by_user_id' => 7,
            'final_decision' => 'approved',
        ]);
    }

    private function insertPendingAsset(Connection $connection, int $assetId, int $organizationId): void
    {
        $connection->insert('asset_upload_intents', [
            'id' => $assetId,
            'organization_id' => $organizationId,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => 'creative.png',
            'object_key' => 'organizations/' . $organizationId . '/assets/campaign-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'status' => 'pending_review',
            'expires_at' => '2026-06-08 00:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => $assetId,
            'upload_intent_id' => $assetId,
            'organization_id' => $organizationId,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => 'organizations/' . $organizationId . '/assets/campaign-' . $assetId . '.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'duration_seconds' => null,
            'checksum' => null,
            'status' => 'pending_review',
        ]);
    }
}
