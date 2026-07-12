<?php

declare(strict_types=1);

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Domain\Billing\BillableAdEvent;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingAllocationStatus;
use VertoAD\Domain\Billing\CpmBillingStream;
use VertoAD\Domain\Billing\CpmRevenueShareSnapshot;
use VertoAD\Repository\Billing\DatabaseCpmBillingRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\CpmBillingService;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\PointsLedgerService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
EnvironmentLoader::load($root);

$database = (string) ($_ENV['TEST_DB_DATABASE'] ?? getenv('TEST_DB_DATABASE') ?: '');
if ($database === '') {
    $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'vertoad') . '_test';
}
if (!str_ends_with(strtolower($database), '_test')) {
    throw new RuntimeException('CPM MySQL rehearsal refuses to run outside a *_test database.');
}

$connection = DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1'),
    'port' => (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
    'dbname' => $database,
    'user' => (string) ($_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'vertoad'),
    'password' => (string) ($_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: ''),
    'charset' => 'utf8mb4',
]);

$assertSame = static function (mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s mismatch: expected %s, got %s',
            $label,
            json_encode($expected, JSON_THROW_ON_ERROR),
            json_encode($actual, JSON_THROW_ON_ERROR),
        ));
    }
};

$baseline = [
    'allocations' => (int) $connection->fetchOne('SELECT COUNT(*) FROM cpm_billing_event_allocations'),
    'accumulators' => (int) $connection->fetchOne('SELECT COUNT(*) FROM cpm_billing_accumulators'),
];

$connection->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $advertiserId = insertOrganization($connection, 'CPM advertiser ' . $suffix, 'cpm-advertiser-' . $suffix);
    $publisherId = insertOrganization($connection, 'CPM publisher ' . $suffix, 'cpm-publisher-' . $suffix);
    $campaignId = insertCampaign($connection, $advertiserId, 'CPM campaign ' . $suffix);
    $siteId = insertSite($connection, $publisherId, 'cpm-' . $suffix . '.example.test');
    $slotId = insertSlot($connection, $siteId, 'cpm-slot-' . $suffix);

    $ledgerRepository = new PointsLedgerRepository($connection);
    $ledger = new PointsLedgerService($ledgerRepository);
    $ledger->credit($advertiserId, 'advertiser_balance', null, 2, 'cpm-rehearsal:initial:' . $suffix);

    $revenueRepository = new RevenueShareRepository($connection);
    $rule = $revenueRepository->createRule(
        'global',
        null,
        null,
        null,
        6_000,
        null,
        new DateTimeImmutable('2026-07-11 09:00:00+00:00'),
    );
    $cpmRepository = new DatabaseCpmBillingRepository($connection);
    $service = new CpmBillingService(
        $cpmRepository,
        new CampaignBudgetService(new CampaignBudgetRepository($connection), $ledger, $ledgerRepository),
        new RevenueShareService($revenueRepository, $ledger),
    );

    $event = static fn (int $number): BillableAdEvent => new BillableAdEvent(
        eventType: 'impression',
        eventId: 'mysql-cpm-' . $suffix . '-' . $number,
        decisionId: 'mysql-cpm-decision-' . $suffix . '-' . $number,
        siteId: $siteId,
        slotId: $slotId,
        publisherOrganizationId: $publisherId,
        advertiserOrganizationId: $advertiserId,
        campaignId: $campaignId,
        adId: 'asset-rehearsal',
        viewerId: 'viewer-rehearsal',
        costPoints: 333,
        valid: true,
        occurredAt: new DateTimeImmutable('2026-07-11 10:00:' . str_pad((string) $number, 2, '0', STR_PAD_LEFT) . '+00:00'),
    );

    $results = [];
    for ($number = 1; $number <= 9; ++$number) {
        $results[$number] = $service->bill($event($number));
    }
    $insufficient = $service->bill($event(10));
    $insufficientReplay = $service->bill($event(10));
    $ledger->credit($advertiserId, 'advertiser_balance', null, 1, 'cpm-rehearsal:recharge:' . $suffix);
    $insufficientAfterRecharge = $service->bill($event(10));
    $afterRecharge = $service->bill($event(11));
    $afterRechargeReplay = $service->bill($event(11));

    $ledger->credit($advertiserId, 'advertiser_balance', null, 1, 'cpm-rehearsal:rule-switch-recharge:' . $suffix);
    $switchedRule = $revenueRepository->createRule(
        'global',
        null,
        null,
        null,
        5_000,
        null,
        new DateTimeImmutable('2026-07-11 10:01:00+00:00'),
    );
    $switchedEvent = new BillableAdEvent(
        eventType: 'impression',
        eventId: 'mysql-cpm-' . $suffix . '-12',
        decisionId: 'mysql-cpm-decision-' . $suffix . '-12',
        siteId: $siteId,
        slotId: $slotId,
        publisherOrganizationId: $publisherId,
        advertiserOrganizationId: $advertiserId,
        campaignId: $campaignId,
        adId: 'asset-rehearsal',
        viewerId: 'viewer-rehearsal',
        costPoints: 670,
        valid: true,
        occurredAt: new DateTimeImmutable('2026-07-11 10:01:12+00:00'),
    );
    $afterRuleSwitch = $service->bill($switchedEvent);
    $afterRuleSwitchReplay = $service->bill($switchedEvent);

    $assertSame(0, $results[1]->grossPoints, 'first gross points');
    $assertSame(1, $results[4]->grossPoints, 'fourth gross points');
    $assertSame(1, $results[7]->grossPoints, 'seventh gross points');
    $assertSame(1, $results[6]->publisherPoints, 'sixth publisher points');
    $assertSame(false, $insufficient->billed, 'insufficient billed flag');
    $assertSame('insufficient_balance', $insufficient->reason, 'insufficient reason');
    $assertSame(true, $insufficientReplay->duplicate, 'insufficient replay flag');
    $assertSame(true, $insufficientAfterRecharge->duplicate, 'post-recharge old replay flag');
    $assertSame('insufficient_balance', $insufficientAfterRecharge->reason, 'post-recharge old replay reason');
    $assertSame(1, $afterRecharge->grossPoints, 'post-recharge new event gross points');
    $assertSame(true, $afterRechargeReplay->duplicate, 'post-recharge new event replay flag');
    $assertSame(1, $afterRuleSwitch->grossPoints, 'rule-switch gross points');
    $assertSame(1, $afterRuleSwitch->publisherPoints, 'rule-switch publisher points');
    $assertSame(true, $afterRuleSwitchReplay->duplicate, 'rule-switch replay flag');

    $stream = $cpmRepository->findAccumulator(new CpmBillingStream(
        $advertiserId,
        $campaignId,
        $publisherId,
        $siteId,
        $slotId,
    ));
    if ($stream === null) {
        throw new RuntimeException('CPM accumulator was not persisted.');
    }
    $assertSame(11, $stream->impressionCount, 'accepted impression count');
    $assertSame(4, $stream->billedPoints, 'cumulative billed points');
    $assertSame(2, $stream->publisherPoints, 'cumulative publisher points');
    $assertSame(0, $stream->grossRemainderMilliPoints, 'gross remainder');
    $assertSame(3_330_000, $stream->publisherShareRemainderNumerator, 'publisher weighted remainder');
    $assertSame(0, $ledgerRepository->balanceForOrganization($advertiserId), 'advertiser balance');
    $assertSame(2, $ledgerRepository->balanceForOrganization($publisherId, 'publisher_earnings'), 'publisher balance');

    $lastEvent = $switchedEvent;
    $lastEventKey = cpmEventKey($lastEvent->decisionId, $lastEvent->eventId);
    $lastAllocation = $cpmRepository->findAllocation($lastEventKey);
    if ($lastAllocation === null) {
        throw new RuntimeException('Final CPM allocation was not persisted.');
    }
    $duplicateClaim = $cpmRepository->claimAllocation(new CpmBillingAllocation(
        id: null,
        eventKey: $lastEventKey,
        eventType: 'impression',
        eventId: $lastEvent->eventId,
        decisionId: $lastEvent->decisionId,
        stream: $lastAllocation->stream,
        revenueShare: new CpmRevenueShareSnapshot($switchedRule->id, 'id:' . $switchedRule->id, 5_000),
        accumulatorId: null,
        bidPointsPerThousand: 670,
        assessedGrossPoints: 0,
        status: CpmBillingAllocationStatus::Processing,
        reason: null,
        grossRemainderBefore: 0,
        grossPoints: 0,
        grossRemainderAfter: 0,
        publisherShareRemainderBefore: 0,
        publisherPoints: 0,
        publisherShareRemainderAfter: 0,
        platformPoints: 0,
        advertiserLedgerEntryId: null,
        publisherLedgerEntryId: null,
        reservationId: null,
        occurredAt: $lastEvent->occurredAt,
        processedAt: null,
    ));
    $assertSame(false, $duplicateClaim->acquired, 'duplicate claim ownership');
    $assertSame(CpmBillingAllocationStatus::Billed, $duplicateClaim->allocation->status, 'duplicate claim final status');

    $statusCounts = $connection->fetchAllKeyValue(
        'SELECT status, COUNT(*) AS count FROM cpm_billing_event_allocations GROUP BY status ORDER BY status',
    );
    $assertSame(['accrued' => 6, 'billed' => 5, 'skipped' => 1], $statusCounts, 'allocation statuses');

    $evidence = [
        'database' => (string) $connection->fetchOne('SELECT DATABASE()'),
        'mysql_version' => (string) $connection->fetchOne('SELECT VERSION()'),
        'allocations' => 12,
        'allocation_statuses' => $statusCounts,
        'impressions_accepted' => $stream->impressionCount,
        'billed_points' => $stream->billedPoints,
        'publisher_points' => $stream->publisherPoints,
        'gross_remainder_milli_points' => $stream->grossRemainderMilliPoints,
        'publisher_share_remainder_numerator' => $stream->publisherShareRemainderNumerator,
        'rule_switch_id' => $switchedRule->id,
        'duplicate_claim_locked' => !$duplicateClaim->acquired,
    ];

    $connection->rollBack();
    $assertSame($baseline['allocations'], (int) $connection->fetchOne('SELECT COUNT(*) FROM cpm_billing_event_allocations'), 'allocation rollback baseline');
    $assertSame($baseline['accumulators'], (int) $connection->fetchOne('SELECT COUNT(*) FROM cpm_billing_accumulators'), 'accumulator rollback baseline');

    fwrite(STDOUT, json_encode($evidence, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }

    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function insertOrganization(Connection $connection, string $name, string $slug): int
{
    $connection->insert('organizations', [
        'name' => $name,
        'slug' => $slug,
        'billing_status' => 'active',
    ]);

    return (int) $connection->lastInsertId();
}

function cpmEventKey(string $decisionId, string $eventId): string
{
    $decisionId = trim($decisionId);
    $eventId = trim($eventId);

    return hash('sha256', "cpm-v1\0"
        . pack('N', strlen($decisionId)) . $decisionId
        . pack('N', strlen($eventId)) . $eventId);
}

function insertCampaign(Connection $connection, int $organizationId, string $name): int
{
    $connection->insert('campaigns', [
        'organization_id' => $organizationId,
        'name' => $name,
        'status' => 'active',
        'objective' => 'traffic',
        'pricing_model' => 'cpm',
        'bid_points' => 333,
        'landing_url' => 'https://advertiser.example.test/offer',
        'targeting_json' => json_encode([
            'devices' => [],
            'geos' => [],
            'site_ids' => [],
            'slot_ids' => [],
            'time_windows' => [],
        ], JSON_THROW_ON_ERROR),
    ]);

    return (int) $connection->lastInsertId();
}

function insertSite(Connection $connection, int $organizationId, string $domain): int
{
    $connection->insert('sites', [
        'organization_id' => $organizationId,
        'name' => 'CPM rehearsal site',
        'domain' => $domain,
        'status' => 'verified',
        'verified_at' => '2026-07-11 09:00:00',
    ]);

    return (int) $connection->lastInsertId();
}

function insertSlot(Connection $connection, int $siteId, string $slotKey): int
{
    $connection->insert('ad_slots', [
        'site_id' => $siteId,
        'name' => 'CPM rehearsal slot',
        'slot_key' => $slotKey,
        'width' => 300,
        'height' => 250,
        'status' => 'active',
    ]);

    return (int) $connection->lastInsertId();
}
