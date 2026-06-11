<?php

declare(strict_types=1);

namespace VertoAD\Tests\Campaigns;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Campaign\CampaignTargeting;
use VertoAD\Repository\Campaign\CampaignRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Service\Campaign\CampaignService;
use VertoAD\Service\Campaign\CampaignValidationException;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;
use ReflectionMethod;

final class CampaignServiceValidationTest extends TestCase
{
    public function testCreateDefaultsTargetingScheduleAndBudgetWhenOptionalFieldsAreMissing(): void
    {
        $connection = $this->connection();
        $this->insertApprovedAsset($connection);
        $service = $this->service($connection);

        $campaign = $service->create(99, [
            'name' => 'Minimal',
            'pricing_model' => 'cpm',
            'bid_points' => 1,
            'landing_url' => 'https://landing.example',
            'creative_asset_id' => 1,
        ]);

        self::assertNull($campaign->startsAt);
        self::assertNull($campaign->endsAt);
        self::assertNull($campaign->budget);
        self::assertSame([], $campaign->targeting->devices);
    }

    public function testActiveCreateRequiresBudgetEvenWhenCreativeIsApproved(): void
    {
        $connection = $this->connection();
        $this->insertApprovedAsset($connection);

        $this->assertCampaignError('campaign_budget_required', function () use ($connection): void {
            $this->service($connection)->create(99, [
                'name' => 'Active',
                'status' => 'active',
                'pricing_model' => 'cpc',
                'bid_points' => 5,
                'landing_url' => 'https://landing.example',
                'creative_asset_id' => 1,
            ]);
        });
    }

    public function testValidationBranchesReturnStableCampaignCodes(): void
    {
        $connection = $this->connection();
        $this->insertApprovedAsset($connection);
        $service = $this->service($connection);

        foreach ([
            ['campaign_name_invalid', ['pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_name_invalid', ['name' => '', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_pricing_model_invalid', ['name' => 'Bad', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_pricing_model_invalid', ['name' => 'Bad', 'pricing_model' => 12, 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_pricing_model_invalid', ['name' => 'Bad', 'pricing_model' => 'cpa', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_bid_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_bid_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 0, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_landing_url_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'creative_asset_id' => 1]],
            ['campaign_landing_url_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'http://landing.example', 'creative_asset_id' => 1]],
            ['campaign_creative_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example']],
            ['campaign_creative_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => '1']],
            ['campaign_status_invalid', ['name' => 'Bad', 'status' => 1, 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_status_invalid', ['name' => 'Bad', 'status' => 'deleted', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1]],
            ['campaign_schedule_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1, 'schedule' => 'soon']],
            ['campaign_targeting_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1, 'targeting' => ['site_ids' => ['10']]]],
            ['campaign_budget_invalid', ['name' => 'Bad', 'pricing_model' => 'cpm', 'bid_points' => 1, 'landing_url' => 'https://landing.example', 'creative_asset_id' => 1, 'budget' => ['total_cap_points' => '100']]],
        ] as [$code, $payload]) {
            $this->assertCampaignError($code, static fn () => $service->create(99, $payload));
        }
    }

    public function testAdditionalTargetingAndBudgetValidationBranches(): void
    {
        $connection = $this->connection();
        $this->insertApprovedAsset($connection);
        $service = $this->service($connection);
        $base = [
            'name' => 'Bad targeting',
            'pricing_model' => 'cpm',
            'bid_points' => 1,
            'landing_url' => 'https://landing.example',
            'creative_asset_id' => 1,
        ];

        foreach ([
            ['targeting' => ['devices' => 'desktop']],
            ['targeting' => ['devices' => ['']]],
            ['targeting' => ['time_windows' => 'weekday']],
            ['targeting' => ['time_windows' => ['bad']]],
            ['targeting' => ['time_windows' => [['day_of_week' => 9, 'start' => '09:00', 'end' => '18:00']]]],
            ['targeting' => ['time_windows' => [['day_of_week' => 1, 'start' => '18:00', 'end' => '09:00']]]],
        ] as $extra) {
            $this->assertCampaignError('campaign_targeting_invalid', fn () => $service->create(99, array_replace_recursive($base, $extra)));
        }

        $this->assertCampaignError('campaign_schedule_invalid', fn () => $service->create(99, $base + ['schedule' => ['starts_at' => 10]]));
        $this->assertCampaignError('campaign_schedule_invalid', fn () => $service->create(99, $base + ['schedule' => ['starts_at' => 'not-a-date']]));
        $campaign = $service->create(99, $base + ['schedule' => ['starts_at' => null, 'ends_at' => null]]);
        self::assertNull($campaign->startsAt);
        self::assertNull($campaign->endsAt);
        $this->assertCampaignError('campaign_targeting_invalid', fn () => $service->create(99, $base + ['targeting' => 'all']));
        $this->assertCampaignError('campaign_targeting_invalid', fn () => $service->create(99, $base + ['targeting' => ['site_ids' => '10']]));
        $this->assertCampaignError('campaign_budget_invalid', fn () => $service->create(99, $base + ['budget' => 'none']));
        $this->assertCampaignError('campaign_budget_invalid', fn () => $service->create(99, $base + ['budget' => ['total_cap_points' => 0]]));
        $this->assertCampaignError('campaign_budget_invalid', fn () => $service->create(99, $base + ['budget' => ['total_cap_points' => 100, 'daily_cap_points' => 101]]));
    }

    public function testBudgetNormalizerMapsDefensiveDomainExceptions(): void
    {
        $service = $this->service($this->connection());
        $method = new ReflectionMethod(CampaignService::class, 'budget');
        $method->setAccessible(true);

        $this->assertCampaignError('campaign_budget_invalid', static fn () => $method->invoke($service, 1, 0, [
            'total_cap_points' => 100,
        ]));
    }

    public function testUpdateCanReuseExistingValuesAndPauseArchivedStatusesWithoutSavingBudget(): void
    {
        $connection = $this->connection();
        $this->insertApprovedAsset($connection);
        $service = $this->service($connection);
        $campaign = $service->create(99, [
            'name' => 'Full',
            'pricing_model' => 'cpm',
            'bid_points' => 10,
            'landing_url' => 'https://landing.example',
            'creative_asset_id' => 1,
            'targeting' => ['devices' => ['desktop', 'desktop'], 'geos' => [], 'site_ids' => [], 'slot_ids' => [], 'time_windows' => []],
            'budget' => ['daily_cap_points' => 100],
        ]);

        $paused = $service->update(99, (int) $campaign->id, ['status' => 'paused']);
        $archived = $service->update(99, (int) $campaign->id, ['status' => 'archived']);

        self::assertSame('paused', $paused->status->value);
        self::assertSame('archived', $archived->status->value);
        self::assertSame(['desktop'], $archived->targeting->devices);
    }

    public function testCampaignTargetingHydratesOnlyValidStoredValues(): void
    {
        $targeting = CampaignTargeting::fromArray([
            'devices' => ['desktop', 12],
            'geos' => ['CN-SH', null],
            'site_ids' => [10, 0, '20'],
            'slot_ids' => [30, -1, '40'],
            'time_windows' => [
                ['day_of_week' => 1, 'start' => '09:00', 'end' => '18:00'],
                ['day_of_week' => 8, 'start' => '09:00', 'end' => '18:00'],
                'bad',
            ],
        ]);

        self::assertSame(['desktop'], $targeting->devices);
        self::assertSame(['CN-SH'], $targeting->geos);
        self::assertSame([10, 20], $targeting->siteIds);
        self::assertSame([30, 40], $targeting->slotIds);
        self::assertCount(1, $targeting->timeWindows);
        self::assertSame([], CampaignTargeting::fromArray(['time_windows' => 'always'])->timeWindows);
    }

    private function connection(): Connection
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

    private function service(Connection $connection): CampaignService
    {
        $ledgerRepository = new PointsLedgerRepository($connection);
        $campaignRepository = new CampaignRepository($connection);

        return new CampaignService(
            $campaignRepository,
            new ReviewRepository($connection),
            new CampaignBudgetService(
                new CampaignBudgetRepository($connection),
                new PointsLedgerService($ledgerRepository),
                $ledgerRepository,
                $campaignRepository,
            ),
        );
    }

    private function insertApprovedAsset(Connection $connection): void
    {
        $connection->insert('asset_upload_intents', [
            'id' => 1,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'original_filename' => 'creative.png',
            'object_key' => 'organizations/99/assets/campaign.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'status' => 'pending_review',
            'expires_at' => '2026-06-08 00:00:00',
        ]);
        $connection->insert('creative_assets', [
            'id' => 1,
            'upload_intent_id' => 1,
            'organization_id' => 99,
            'uploader_user_id' => 7,
            'type' => 'image',
            'object_key' => 'organizations/99/assets/campaign.png',
            'content_type' => 'image/png',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'duration_seconds' => null,
            'checksum' => null,
            'status' => 'pending_review',
        ]);
        $connection->insert('creative_reviews', [
            'asset_id' => 1,
            'organization_id' => 99,
            'status' => 'approved',
            'requested_by_user_id' => 7,
            'final_decision' => 'approved',
            'final_decided_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param callable(): mixed $callback */
    private function assertCampaignError(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected campaign validation exception.');
        } catch (CampaignValidationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
