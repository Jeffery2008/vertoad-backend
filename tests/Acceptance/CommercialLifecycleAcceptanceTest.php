<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use Defuse\Crypto\Key;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Domain\Billing\WithdrawalPaymentStatus;
use VertoAD\Domain\Billing\WithdrawalProofStatus;
use VertoAD\Domain\Billing\WithdrawalReviewStatus;
use VertoAD\Domain\Serving\ServingRequestContext;
use VertoAD\Http\Action\Serving\ClickAction;
use VertoAD\Http\Action\Serving\ServeAction;
use VertoAD\Http\Action\Serving\TrackAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RequestIdMiddleware;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\StoredObjectInspection;
use VertoAD\Repository\Assets\AssetRepository;
use VertoAD\Repository\Attribution\DatabaseAttributionEventRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\Campaign\CampaignRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\RechargeKeyRepository;
use VertoAD\Repository\Reporting\DatabaseConversionPathRepository;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Repository\Serving\DatabaseAdCandidateRepository;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\Serving\DatabaseServingInventoryRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Attribution\AttributionService;
use VertoAD\Service\Billing\AdEventBillingService;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\Campaign\CampaignService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\Cron\AggregateStatisticsJob;
use VertoAD\Service\Cron\EventConsumptionJob;
use VertoAD\Service\DefuseRechargeKeyPlaintextCipher;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\Reporting\ConversionPathReportService;
use VertoAD\Service\Reporting\ReportQueryService;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\ReviewService;
use VertoAD\Service\Serving\AdServingService;
use VertoAD\Service\Serving\DatabaseServingRiskAssessor;
use VertoAD\Service\Serving\DefaultAdSelectionPolicy;
use VertoAD\Service\Serving\InMemoryServingFrequencyCapStore;
use VertoAD\Tests\Assets\InMemoryObjectStorageInspector;
use VertoAD\Tests\Billing\BillingTask14Schema;
use VertoAD\Tests\Campaigns\CampaignSchema;

final class CommercialLifecycleAcceptanceTest extends TestCase
{
    private const int ADVERTISER_ORGANIZATION_ID = 99;
    private const int PUBLISHER_ORGANIZATION_ID = 42;
    private const int ADVERTISER_USER_ID = 7;
    private const int PUBLISHER_USER_ID = 8;
    private const int ADMIN_USER_ID = 1;
    private const int SITE_ID = 5;
    private const int SLOT_ID = 10;
    private const string VIEWER_ID = 'commercial-viewer';

    public function testCommercialLifecycleFromRechargeThroughRoiAndPublisherPayout(): void
    {
        $connection = $this->createConnection();
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);

        $recharge = new RechargeKeyService(
            new RechargeKeyRepository($connection),
            $ledger,
            new DefuseRechargeKeyPlaintextCipher(Key::createNewRandomKey()->saveToAsciiSafeString()),
        );
        $issuedKey = $recharge->issue(
            plaintextKey: 'rk_live_COMMERCIAL_ACCEPTANCE',
            pointsAmount: 1_000,
            batchCode: 'commercial-acceptance',
            batchMetadata: ['purpose' => 'cross-module-lifecycle'],
            expiresAt: null,
            issuedByUserId: self::ADMIN_USER_ID,
            organizationId: null,
        );
        $redemption = $recharge->redeem(
            'rk_live_COMMERCIAL_ACCEPTANCE',
            self::ADVERTISER_ORGANIZATION_ID,
            self::ADVERTISER_USER_ID,
            new DateTimeImmutable('2026-07-10T08:00:00+00:00'),
        );
        $redemptionReplay = $recharge->redeem(
            'rk_live_COMMERCIAL_ACCEPTANCE',
            self::ADVERTISER_ORGANIZATION_ID,
            self::ADVERTISER_USER_ID,
            new DateTimeImmutable('2026-07-10T08:01:00+00:00'),
        );

        self::assertNotNull($issuedKey->id);
        self::assertSame($redemption->ledgerEntry->id, $redemptionReplay->ledgerEntry->id);
        self::assertSame(1_000, $ledgerRepository->balanceForOrganization(self::ADVERTISER_ORGANIZATION_ID));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM ledger_entries WHERE reference_type = 'recharge_key'"));
        self::assertStringNotContainsString(
            'rk_live_COMMERCIAL_ACCEPTANCE',
            (string) $connection->fetchOne('SELECT encrypted_plaintext_key FROM recharge_keys WHERE id = ?', [$issuedKey->id]),
        );

        $signer = $this->signer('commercial-assets');
        $inspector = new InMemoryObjectStorageInspector();
        $assetUploads = new AssetUploadService(
            new AssetRepository($connection),
            $signer,
            $inspector,
            tokenGenerator: static fn (): string => 'commercial-image',
        );
        $uploadIntent = $assetUploads->createUploadIntent(
            organizationId: self::ADVERTISER_ORGANIZATION_ID,
            uploaderUserId: self::ADVERTISER_USER_ID,
            type: 'image',
            filename: 'commercial.png',
            contentType: 'image/png',
            byteSize: 1_024,
        );
        $inspector->put(new StoredObjectInspection(
            objectKey: $uploadIntent->objectKey,
            contentType: 'image/png',
            byteSize: 1_024,
            width: 300,
            height: 250,
            durationSeconds: null,
            checksum: 'sha256:commercial-image',
            leadingBytes: "\x89PNG\r\n\x1a\n",
        ));
        $asset = $assetUploads->confirmUploadedAsset(
            organizationId: self::ADVERTISER_ORGANIZATION_ID,
            uploaderUserId: self::ADVERTISER_USER_ID,
            uploadIntentId: (int) $uploadIntent->id,
            objectKey: $uploadIntent->objectKey,
            contentType: 'image/png',
            byteSize: 1_024,
            checksum: 'sha256:commercial-image',
        );

        self::assertSame('pending_review', $asset->status->value);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM asset_snapshot_jobs WHERE asset_id = ?', [$asset->id]));

        $reviewRepository = new ReviewRepository($connection);
        $budgetService = new CampaignBudgetService(
            new CampaignBudgetRepository($connection),
            $ledger,
            $ledgerRepository,
        );
        $campaigns = new CampaignService(
            new CampaignRepository($connection),
            $reviewRepository,
            $budgetService,
        );
        $campaign = $campaigns->create(self::ADVERTISER_ORGANIZATION_ID, [
            'name' => 'Commercial acceptance campaign',
            'pricing_model' => 'cpm',
            'bid_points' => 40,
            'landing_url' => 'https://advertiser.example/offer',
            'creative_asset_id' => (int) $asset->id,
            'targeting' => [
                'site_ids' => [self::SITE_ID],
                'slot_ids' => [self::SLOT_ID],
            ],
            'budget' => [
                'total_cap_points' => 900,
                'daily_cap_points' => 500,
                'hourly_cap_points' => 250,
            ],
        ]);
        self::assertSame('draft', $campaign->status->value);

        $reviews = new ReviewService(
            $reviewRepository,
            new DeterministicCreativeReviewProvider([
                'provider' => 'commercial-acceptance-ai',
                'model' => 'commercial-review-v1',
                'risk_score' => 0.05,
                'risk_labels' => ['low_risk'],
                'reasons' => ['Image and landing page require human final review.'],
            ]),
        );
        $aiReview = $reviews->requestAiReview(
            self::ADVERTISER_ORGANIZATION_ID,
            self::ADVERTISER_USER_ID,
            (int) $asset->id,
            'https://advertiser.example/offer',
            'Commercial offer',
        );
        self::assertSame('needs_human', $aiReview->status->value);
        self::assertSame('commercial-acceptance-ai', $aiReview->aiProvider);

        $approvedReview = $reviews->approve(
            self::ADVERTISER_ORGANIZATION_ID,
            self::ADMIN_USER_ID,
            (int) $aiReview->id,
            'Manual policy review passed.',
        );
        self::assertSame('approved', $approvedReview->status->value);
        self::assertSame('approved', $approvedReview->finalDecision);

        $campaign = $campaigns->update(
            self::ADVERTISER_ORGANIZATION_ID,
            (int) $campaign->id,
            ['status' => 'active'],
        );
        self::assertSame('active', $campaign->status->value);

        $revenueShares = new RevenueShareRepository($connection);
        $revenueShares->createRule(
            'global',
            null,
            null,
            null,
            6_000,
            self::ADMIN_USER_ID,
            new DateTimeImmutable('2026-07-10T08:02:00+00:00'),
        );

        $riskAssessor = new DatabaseServingRiskAssessor(new DatabaseFraudRiskFeatureRepository($connection));
        self::assertTrue($riskAssessor->assess(self::SITE_ID, self::SLOT_ID, self::VIEWER_ID)->allowed);

        $eventBuffer = new InMemoryAdEventRepository();
        $decisionRepository = new DatabaseAdDecisionRepository($connection);
        $serving = new AdServingService(
            new DatabaseServingInventoryRepository($connection),
            new DatabaseAdCandidateRepository($connection),
            $decisionRepository,
            $eventBuffer,
            $budgetService,
            new DefaultAdSelectionPolicy(new InMemoryServingFrequencyCapStore(), $riskAssessor),
        );

        $cpmServeAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 minutes');
        $cpmDecision = $serving->serve(
            self::SITE_ID,
            self::SLOT_ID,
            self::VIEWER_ID,
            ['width' => 300, 'height' => 250],
            false,
            $cpmServeAt,
            new ServingRequestContext(requestId: 'commercial-cpm-serve'),
        );
        self::assertTrue($cpmDecision->filled);
        self::assertSame(40, $cpmDecision->impressionCostPoints);
        self::assertSame(0, $cpmDecision->clickCostPoints);

        $cpmImpression = $serving->trackImpression(
            $cpmDecision->decisionId,
            self::VIEWER_ID,
            0.75,
            1_500,
            'commercial-cpm-impression',
            $cpmServeAt->modify('+1 second'),
            'commercial-cpm-impression-request',
        );
        $priorClick = $serving->recordClick(
            $cpmDecision->decisionId,
            self::VIEWER_ID,
            'commercial-prior-click',
            $cpmServeAt->modify('+2 seconds'),
            'commercial-prior-click-request',
        );
        self::assertTrue($cpmImpression->accepted);
        self::assertTrue($priorClick->accepted);

        $campaign = $campaigns->update(
            self::ADVERTISER_ORGANIZATION_ID,
            (int) $campaign->id,
            ['pricing_model' => 'cpc', 'bid_points' => 120],
        );
        self::assertSame('cpc', $campaign->pricingModel->value);

        $app = $this->createServingApp($serving);
        $cpcServe = $this->handleJson($app, 'POST', '/api/v1/ads/serve', [
            'site_id' => self::SITE_ID,
            'slot_id' => self::SLOT_ID,
            'viewer_id' => self::VIEWER_ID,
            'size' => ['width' => 300, 'height' => 250],
        ]);
        self::assertTrue($cpcServe['data']['filled']);
        self::assertSame((int) $campaign->id, $cpcServe['data']['ad']['campaign_id']);
        $cpcDecisionId = (string) $cpcServe['data']['decision_id'];
        self::assertNotSame($cpmDecision->decisionId, $cpcDecisionId);

        $storedCpcDecision = $decisionRepository->find($cpcDecisionId);
        self::assertNotNull($storedCpcDecision);
        self::assertSame(0, $storedCpcDecision->impressionCostPoints);
        self::assertSame(120, $storedCpcDecision->clickCostPoints);

        $cpcImpressionPayload = [
            'decision_id' => $cpcDecisionId,
            'viewer_id' => self::VIEWER_ID,
            'event_id' => 'commercial-cpc-impression',
            'visible_ratio' => 0.75,
            'visible_ms' => 1_500,
        ];
        $cpcImpression = $this->handleJson($app, 'POST', '/api/v1/ads/track', $cpcImpressionPayload);
        $cpcImpressionReplay = $this->handleJson($app, 'POST', '/api/v1/ads/track', $cpcImpressionPayload);
        self::assertTrue($cpcImpression['data']['accepted']);
        self::assertFalse($cpcImpression['data']['duplicate']);
        self::assertTrue($cpcImpressionReplay['data']['duplicate']);

        $clickUri = '/api/v1/ads/click?' . http_build_query([
            'decision_id' => $cpcDecisionId,
            'viewer_id' => self::VIEWER_ID,
            'event_id' => 'commercial-cpc-click',
        ], '', '&', PHP_QUERY_RFC3986);
        $click = $this->handleRaw($app, 'GET', $clickUri);
        $clickReplay = $this->handleRaw($app, 'GET', $clickUri);
        self::assertSame(302, $click->getStatusCode());
        self::assertSame('https://advertiser.example/offer', $click->getHeaderLine('Location'));
        self::assertSame(302, $clickReplay->getStatusCode());
        self::assertSame(4, count($eventBuffer->events()));

        $persistence = new DatabaseAdEventRepository($connection);
        $billing = new AdEventBillingService(
            $budgetService,
            new RevenueShareService($revenueShares, $ledger),
            $connection,
        );
        $eventConsumption = new EventConsumptionJob($eventBuffer, $persistence, $billing, 100);
        $consumed = $eventConsumption->run();
        self::assertSame([
            'consumed' => 4,
            'billed' => 2,
            'skipped' => 2,
            'duplicates' => 0,
            'failed' => 0,
        ], $consumed->metrics);
        self::assertSame(0, $eventConsumption->run()->metrics['consumed']);

        self::assertSame(840, $ledgerRepository->balanceForOrganization(self::ADVERTISER_ORGANIZATION_ID));
        self::assertSame(96, $ledgerRepository->balanceForOrganization(self::PUBLISHER_ORGANIZATION_ID, 'publisher_earnings'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM publisher_earning_events'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM spend_reservations'));
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM ad_serving_events'));
        self::assertSame(40, (int) $connection->fetchOne("SELECT billed_points FROM ad_serving_events WHERE event_id = 'commercial-cpm-impression'"));
        self::assertSame('skipped', (string) $connection->fetchOne("SELECT billing_status FROM ad_serving_events WHERE event_id = 'commercial-cpc-impression'"));
        self::assertSame(120, (int) $connection->fetchOne("SELECT billed_points FROM ad_serving_events WHERE event_id = 'commercial-cpc-click'"));

        $persistedClick = $persistence->findEvent('click', 'commercial-cpc-click');
        self::assertNotNull($persistedClick);
        $billingReplay = $billing->billServingEvent($persistedClick);
        self::assertTrue($billingReplay->billed);
        self::assertTrue($billingReplay->duplicate);
        self::assertSame(840, $ledgerRepository->balanceForOrganization(self::ADVERTISER_ORGANIZATION_ID));
        self::assertSame(96, $ledgerRepository->balanceForOrganization(self::PUBLISHER_ORGANIZATION_ID, 'publisher_earnings'));

        $clickOccurredAt = new DateTimeImmutable(
            (string) $connection->fetchOne("SELECT occurred_at FROM ad_serving_events WHERE event_id = 'commercial-cpc-click'"),
            new DateTimeZone('UTC'),
        );
        $conversionOccurredAt = $clickOccurredAt->modify('+10 seconds');
        $attribution = new AttributionService(new DatabaseAttributionEventRepository($connection), 604_800);
        $conversionPayload = [
            'event_id' => 'commercial-purchase',
            'viewer_id' => self::VIEWER_ID,
            'conversion_name' => 'purchase',
            'value_points' => 800,
            'occurred_at' => $conversionOccurredAt->format(DATE_ATOM),
        ];
        $conversion = $attribution->recordServerApiConversion(
            $conversionPayload,
            self::ADVERTISER_ORGANIZATION_ID,
            501,
            self::ADVERTISER_USER_ID,
        );
        $conversionReplay = $attribution->recordServerApiConversion(
            $conversionPayload,
            self::ADVERTISER_ORGANIZATION_ID,
            501,
            self::ADVERTISER_USER_ID,
        );
        self::assertTrue($conversion->attributed);
        self::assertSame('commercial-cpc-click', $conversion->clickEventId);
        self::assertNotSame('commercial-prior-click', $conversion->clickEventId);
        self::assertSame((int) $campaign->id, $conversion->campaignId);
        self::assertFalse($conversion->duplicate);
        self::assertTrue($conversionReplay->duplicate);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM attribution_conversions'));

        $reportFrom = $cpmServeAt->setTime(0, 0);
        $reportTo = $conversionOccurredAt->setTime(0, 0)->modify('+1 day');
        $aggregateRepository = new DatabaseReportAggregateRepository($connection);
        $aggregateJob = new AggregateStatisticsJob($aggregateRepository, $reportFrom, $reportTo);
        $firstAggregate = $aggregateJob->run();
        $aggregateRowCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM report_aggregates');
        $secondAggregate = $aggregateJob->run();
        self::assertSame($firstAggregate->metrics, $secondAggregate->metrics);
        self::assertSame($aggregateRowCount, (int) $connection->fetchOne('SELECT COUNT(*) FROM report_aggregates'));

        $dashboard = (new ReportQueryService($aggregateRepository))->dashboard([
            'portal' => 'advertiser',
            'organization_id' => self::ADVERTISER_ORGANIZATION_ID,
            'campaign_id' => (int) $campaign->id,
            'from' => $reportFrom,
            'to' => $reportTo,
            'granularity' => 'day',
        ]);
        self::assertSame(2, $dashboard['totals']['impressions']);
        self::assertSame(2, $dashboard['totals']['clicks']);
        self::assertSame(100.0, $dashboard['totals']['ctr']);
        self::assertSame(160, $dashboard['totals']['spend_points']);
        self::assertSame(1, $dashboard['totals']['conversions']);
        self::assertSame(800, $dashboard['totals']['conversion_value_points']);
        self::assertSame(5.0, $dashboard['totals']['roi']);

        $publisherDashboard = (new ReportQueryService($aggregateRepository))->dashboard([
            'portal' => 'publisher',
            'organization_id' => self::PUBLISHER_ORGANIZATION_ID,
            'campaign_id' => (int) $campaign->id,
            'from' => $reportFrom,
            'to' => $reportTo,
            'granularity' => 'day',
        ]);
        self::assertSame(96, $publisherDashboard['totals']['revenue_points']);

        $conversionPaths = (new ConversionPathReportService(new DatabaseConversionPathRepository($connection)))->conversionPaths([
            'portal' => 'advertiser',
            'organization_id' => self::ADVERTISER_ORGANIZATION_ID,
            'campaign_id' => (int) $campaign->id,
            'from' => $reportFrom,
            'to' => $reportTo,
            'max_touchpoints' => 10,
        ]);
        self::assertSame(1, $conversionPaths['summary']['attributed_conversions']);
        self::assertSame(800, $conversionPaths['summary']['conversion_value_points']);
        self::assertCount(1, $conversionPaths['paths']);
        self::assertSame(
            ['commercial-cpm-impression', 'commercial-prior-click', 'commercial-cpc-impression', 'commercial-cpc-click'],
            array_column($conversionPaths['paths'][0]['touchpoints'], 'event_id'),
        );

        $withdrawalRepository = new WithdrawalRepository($connection);
        $withdrawals = new WithdrawalService($withdrawalRepository, $ledger, $ledgerRepository);
        $withdrawal = $withdrawals->requestWithdrawal(
            organizationId: self::PUBLISHER_ORGANIZATION_ID,
            requestedByUserId: self::PUBLISHER_USER_ID,
            pointsAmount: 90,
            payoutMethod: 'bank_transfer',
            payoutAccount: ['account_no' => 'acceptance-account'],
            notes: 'Commercial acceptance payout.',
            idempotencyKey: 'commercial-withdrawal',
            now: $conversionOccurredAt->modify('+1 minute'),
        );
        $withdrawalReplay = $withdrawals->requestWithdrawal(
            organizationId: self::PUBLISHER_ORGANIZATION_ID,
            requestedByUserId: self::PUBLISHER_USER_ID,
            pointsAmount: 90,
            payoutMethod: 'bank_transfer',
            payoutAccount: ['account_no' => 'acceptance-account'],
            notes: 'Commercial acceptance payout.',
            idempotencyKey: 'commercial-withdrawal',
            now: $conversionOccurredAt->modify('+2 minutes'),
        );
        self::assertSame($withdrawal->id, $withdrawalReplay->id);
        self::assertSame('0.90', $withdrawal->amountCny);
        self::assertCount(1, $withdrawals->listQueue(
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            self::PUBLISHER_ORGANIZATION_ID,
            10,
        ));
        $approved = $withdrawals->approve(
            (int) $withdrawal->id,
            self::ADMIN_USER_ID,
            'Reviewed for manual payout.',
            $conversionOccurredAt->modify('+3 minutes'),
        );
        self::assertSame(WithdrawalReviewStatus::Approved, $approved->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::Pending, $approved->paymentStatus);

        $proofInspector = new InMemoryObjectStorageInspector();
        $proofs = new WithdrawalProofService(
            $withdrawalRepository,
            $this->signer('withdrawal-proofs'),
            static fn (): string => 'commercial-proof',
            $proofInspector,
        );
        $proofIntent = $proofs->createUploadIntent(
            (int) $withdrawal->id,
            self::ADMIN_USER_ID,
            'payout.pdf',
            'application/pdf',
            4_096,
            $conversionOccurredAt->modify('+4 minutes'),
        );
        $proofBody = "%PDF-1.7\ncommercial payment receipt";
        $proofChecksum = 'sha256:' . hash('sha256', $proofBody);
        $proofInspector->put(new StoredObjectInspection(
            $proofIntent->proof->objectKey,
            'application/pdf',
            4_096,
            1,
            1,
            null,
            $proofChecksum,
            $proofBody,
        ));
        $proof = $proofs->confirmUploadedProof(
            (int) $proofIntent->proof->id,
            (int) $withdrawal->id,
            self::ADMIN_USER_ID,
            $conversionOccurredAt->modify('+5 minutes'),
        );
        self::assertSame(WithdrawalProofStatus::Verified, $proof->status);

        $paid = $withdrawals->markPaid(
            (int) $withdrawal->id,
            self::ADMIN_USER_ID,
            (int) $proof->id,
            'Reviewed and paid manually.',
            $conversionOccurredAt->modify('+6 minutes'),
        );
        $paidReplay = $withdrawals->markPaid(
            (int) $withdrawal->id,
            self::ADMIN_USER_ID,
            (int) $proof->id,
            'Reviewed and paid manually.',
            $conversionOccurredAt->modify('+7 minutes'),
        );
        self::assertSame(WithdrawalPaymentStatus::Paid, $paid->paymentStatus);
        self::assertSame(WithdrawalPaymentStatus::Paid, $paidReplay->paymentStatus);
        self::assertSame(6, $ledgerRepository->balanceForOrganization(self::PUBLISHER_ORGANIZATION_ID, 'publisher_earnings'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'paid'"));
    }

    private function createConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CampaignSchema::create($connection);
        BillingTask14Schema::create($connection);

        $connection->executeStatement(
            'CREATE TABLE recharge_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NULL,
                key_hash TEXT NOT NULL UNIQUE,
                encrypted_plaintext_key TEXT NOT NULL,
                points_amount INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "issued",
                batch_code TEXT NULL,
                batch_metadata_json TEXT NULL,
                issued_by_user_id INTEGER NULL,
                redeemed_by_user_id INTEGER NULL,
                redeemed_ledger_entry_id INTEGER NULL,
                expires_at TEXT NULL,
                redeemed_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE ad_serving_decisions (
                decision_id VARCHAR(160) PRIMARY KEY,
                site_id INTEGER NOT NULL,
                slot_id INTEGER NOT NULL,
                viewer_id VARCHAR(160) NOT NULL,
                filled INTEGER NOT NULL,
                reason VARCHAR(120) NULL,
                iframe_html TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                ad_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                advertiser_organization_id INTEGER NULL,
                publisher_organization_id INTEGER NULL,
                impression_cost_points INTEGER NULL,
                click_cost_points INTEGER NULL,
                landing_url TEXT NULL,
                decided_at DATETIME NOT NULL,
                request_id VARCHAR(160) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(512) NULL,
                geo_code VARCHAR(64) NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE attribution_conversions (
                event_id VARCHAR(160) PRIMARY KEY,
                conversion_id VARCHAR(160) NOT NULL,
                organization_id INTEGER NULL,
                oauth_client_id INTEGER NULL,
                recorded_by_user_id INTEGER NULL,
                attributed INTEGER NOT NULL,
                click_event_id VARCHAR(160) NULL,
                decision_id VARCHAR(160) NULL,
                campaign_id INTEGER NULL,
                window_seconds INTEGER NOT NULL,
                source VARCHAR(64) NOT NULL,
                conversion_name VARCHAR(160) NOT NULL,
                value_points INTEGER NOT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE fraud_risk_features (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_type VARCHAR(24) NOT NULL,
                scope_id VARCHAR(160) NOT NULL,
                window_start DATETIME NOT NULL,
                window_end DATETIME NOT NULL,
                site_id INTEGER NULL,
                slot_id INTEGER NULL,
                viewer_id VARCHAR(160) NULL,
                impressions INTEGER NOT NULL,
                clicks INTEGER NOT NULL,
                invalid_clicks INTEGER NOT NULL,
                ctr_per_mille INTEGER NOT NULL,
                invalid_click_rate_per_mille INTEGER NOT NULL,
                risk_score INTEGER NOT NULL,
                risk_bucket VARCHAR(24) NOT NULL,
                reasons_json TEXT NOT NULL,
                computed_at DATETIME NOT NULL
            )',
        );

        $connection->insert('sites', [
            'id' => self::SITE_ID,
            'organization_id' => self::PUBLISHER_ORGANIZATION_ID,
            'name' => 'Commercial publisher',
            'domain' => 'publisher.example',
            'status' => 'verified',
        ]);
        $connection->insert('ad_slots', [
            'id' => self::SLOT_ID,
            'site_id' => self::SITE_ID,
            'name' => 'Commercial rectangle',
            'slot_key' => 'commercial-rectangle',
            'width' => 300,
            'height' => 250,
            'status' => 'active',
        ]);
        $this->insertLowRiskFeature($connection, 'viewer', self::VIEWER_ID, self::VIEWER_ID);
        $this->insertLowRiskFeature($connection, 'slot', (string) self::SLOT_ID, null);

        return $connection;
    }

    private function insertLowRiskFeature(
        Connection $connection,
        string $scopeType,
        string $scopeId,
        ?string $viewerId,
    ): void {
        $connection->insert('fraud_risk_features', [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'window_start' => '2026-07-10 00:00:00',
            'window_end' => '2026-07-11 00:00:00',
            'site_id' => self::SITE_ID,
            'slot_id' => self::SLOT_ID,
            'viewer_id' => $viewerId,
            'impressions' => 10,
            'clicks' => 1,
            'invalid_clicks' => 0,
            'ctr_per_mille' => 100,
            'invalid_click_rate_per_mille' => 0,
            'risk_score' => 15,
            'risk_bucket' => 'low',
            'reasons_json' => '[]',
            'computed_at' => '2026-07-11 00:00:01',
        ]);
    }

    private function createServingApp(AdServingService $serving): App
    {
        $container = (new ContainerBuilder())->addDefinitions([
            AdServingService::class => static fn (): AdServingService => $serving,
            ServeAction::class => static fn (): ServeAction => new ServeAction($serving),
            TrackAction::class => static fn (): TrackAction => new TrackAction($serving),
            ClickAction::class => static fn (): ClickAction => new ClickAction($serving),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->post('/api/v1/ads/serve', ServeAction::class);
        $app->post('/api/v1/ads/track', TrackAction::class);
        $app->get('/api/v1/ads/click', ClickAction::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);
        $app->add(new RequestIdMiddleware());

        return $app;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function handleJson(App $app, string $method, string $uri, ?array $payload = null): array
    {
        $response = $this->handleRaw($app, $method, $uri, $payload);
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed>|null $payload */
    private function handleRaw(App $app, string $method, string $uri, ?array $payload = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, [
            'REMOTE_ADDR' => '198.51.100.24',
        ])->withHeader('User-Agent', 'CommercialAcceptance/1.0');
        if ($payload !== null) {
            $request = $request->withParsedBody($payload);
        }

        return $app->handle($request);
    }

    private function signer(string $bucket): DeterministicPresignedUploadSigner
    {
        return new DeterministicPresignedUploadSigner([
            'endpoint' => 'https://storage.example.test',
            'bucket' => $bucket,
            'access_key_id' => 'commercial-access-key',
            'secret_access_key' => 'commercial-secret-key',
            'path_style_endpoint' => true,
        ]);
    }
}
