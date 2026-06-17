<?php

declare(strict_types=1);

namespace VertoAD\Tests\Campaigns;

use PHPUnit\Framework\TestCase;

final class CampaignStatusSchemaMigrationTest extends TestCase
{
    public function testCampaignStatusMigrationAddsDeliveryStatusCheck(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260618010000_add_campaign_status_check.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';

        self::assertStringContainsString('alter table campaigns add constraint chk_campaigns_status', $sql);
        self::assertStringContainsString("check (status in ('draft', 'active', 'paused', 'archived'))", $sql);
        self::assertStringContainsString('alter table campaigns drop check chk_campaigns_status', $sql);
    }
}
