<?php

declare(strict_types=1);

namespace VertoAD\Tests\Campaigns;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Campaign\CampaignStatus;
use VertoAD\Repository\Campaign\CampaignRepository;

final class CampaignRepositoryTest extends TestCase
{
    public function testPauseIfActivePausesActiveCampaignAndPersistsReason(): void
    {
        $connection = $this->connection();
        $this->insertCampaign($connection, id: 10, organizationId: 99, status: CampaignStatus::Active->value);

        $paused = (new CampaignRepository($connection))->pauseIfActive(99, 10, 'total_cap_exhausted');

        self::assertTrue($paused);
        self::assertSame(
            [
                'status' => CampaignStatus::Paused->value,
                'pause_reason' => 'total_cap_exhausted',
            ],
            $this->campaignState($connection, 10),
        );

        $campaign = (new CampaignRepository($connection))->find(99, 10);
        self::assertNotNull($campaign);
        self::assertSame('total_cap_exhausted', $campaign->pauseReason);
    }

    public function testPauseIfActiveDoesNotOverwriteNonActiveCampaigns(): void
    {
        $connection = $this->connection();
        $this->insertCampaign(
            $connection,
            id: 11,
            organizationId: 99,
            status: CampaignStatus::Draft->value,
            pauseReason: null,
        );
        $this->insertCampaign(
            $connection,
            id: 12,
            organizationId: 99,
            status: CampaignStatus::Paused->value,
            pauseReason: 'manual_pause',
        );
        $repository = new CampaignRepository($connection);

        self::assertFalse($repository->pauseIfActive(99, 11, 'insufficient_balance'));
        self::assertFalse($repository->pauseIfActive(99, 12, 'insufficient_balance'));

        self::assertSame(
            ['status' => CampaignStatus::Draft->value, 'pause_reason' => null],
            $this->campaignState($connection, 11),
        );
        self::assertSame(
            ['status' => CampaignStatus::Paused->value, 'pause_reason' => 'manual_pause'],
            $this->campaignState($connection, 12),
        );
    }

    public function testPauseIfActiveReturnsFalseForWrongOrganizationOrCampaign(): void
    {
        $connection = $this->connection();
        $this->insertCampaign($connection, id: 13, organizationId: 99, status: CampaignStatus::Active->value);
        $repository = new CampaignRepository($connection);

        self::assertFalse($repository->pauseIfActive(100, 13, 'insufficient_balance'));
        self::assertFalse($repository->pauseIfActive(99, 999, 'insufficient_balance'));

        self::assertSame(
            ['status' => CampaignStatus::Active->value, 'pause_reason' => null],
            $this->campaignState($connection, 13),
        );
    }

    public function testPauseIfActiveRejectsInvalidIdentifiersAndBlankReason(): void
    {
        $connection = $this->connection();
        $this->insertCampaign($connection, id: 14, organizationId: 99, status: CampaignStatus::Active->value);
        $repository = new CampaignRepository($connection);

        self::assertFalse($repository->pauseIfActive(0, 14, 'insufficient_balance'));
        self::assertFalse($repository->pauseIfActive(99, 0, 'insufficient_balance'));
        self::assertFalse($repository->pauseIfActive(99, 14, '   '));
        self::assertSame(
            ['status' => CampaignStatus::Active->value, 'pause_reason' => null],
            $this->campaignState($connection, 14),
        );
    }

    public function testCampaignReadsFailClosedForMalformedOrNonObjectTargetingJson(): void
    {
        $connection = $this->connection();
        $this->insertCampaign(
            $connection,
            id: 15,
            organizationId: 99,
            status: CampaignStatus::Active->value,
            targetingJson: '{bad-json',
        );
        $this->insertCampaign(
            $connection,
            id: 16,
            organizationId: 99,
            status: CampaignStatus::Active->value,
            targetingJson: 'null',
        );
        $this->insertCampaign(
            $connection,
            id: 17,
            organizationId: 99,
            status: CampaignStatus::Active->value,
            targetingJson: '[]',
        );
        $repository = new CampaignRepository($connection);

        try {
            $repository->find(99, 15);
            self::fail('Malformed targeting JSON must not become unrestricted targeting.');
        } catch (JsonException) {
            self::assertTrue(true);
        }

        try {
            $repository->find(99, 16);
            self::fail('Null targeting JSON must not become unrestricted targeting.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stored campaign targeting must be a JSON object.');
        $repository->find(99, 17);
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        CampaignSchema::create($connection);

        return $connection;
    }

    private function insertCampaign(
        Connection $connection,
        int $id,
        int $organizationId,
        string $status,
        ?string $pauseReason = null,
        string $targetingJson = '{}',
    ): void {
        $connection->insert('campaigns', [
            'id' => $id,
            'organization_id' => $organizationId,
            'name' => 'Campaign ' . $id,
            'status' => $status,
            'pause_reason' => $pauseReason,
            'pricing_model' => 'cpm',
            'bid_points' => 100,
            'landing_url' => 'https://landing.example/campaign-' . $id,
            'creative_asset_id' => 1,
            'starts_at' => null,
            'ends_at' => null,
            'targeting_json' => $targetingJson,
        ]);
    }

    /** @return array{status: string, pause_reason: string|null} */
    private function campaignState(Connection $connection, int $campaignId): array
    {
        $row = $connection->fetchAssociative(
            'SELECT status, pause_reason FROM campaigns WHERE id = ?',
            [$campaignId],
        );
        self::assertIsArray($row);

        return [
            'status' => (string) $row['status'],
            'pause_reason' => $row['pause_reason'] === null ? null : (string) $row['pause_reason'],
        ];
    }
}
