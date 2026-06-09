<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;

final class ServingFrequencyCapMigrationTest extends TestCase
{
    public function testMigrationDefinesCampaignServingFrequencyCaps(): void
    {
        $path = dirname(__DIR__, 2) . '/db/migrations/20260610120000_create_campaign_serving_frequency_caps.php';
        self::assertFileExists($path);

        $sql = preg_replace('/\s+/', ' ', strtolower((string) file_get_contents($path))) ?? '';
        foreach ([
            'create table campaign_serving_frequency_caps',
            'campaign_id bigint unsigned not null',
            'organization_id bigint unsigned not null',
            'hourly_impression_cap int unsigned null',
            'daily_impression_cap int unsigned null',
            'hourly_click_cap int unsigned null',
            'daily_click_cap int unsigned null',
            'primary key (campaign_id)',
            'constraint fk_campaign_serving_frequency_caps_campaign foreign key (campaign_id) references campaigns (id) on delete cascade',
            'constraint chk_campaign_serving_frequency_caps_hourly_impression check (hourly_impression_cap is null or hourly_impression_cap > 0)',
            'drop table if exists campaign_serving_frequency_caps',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }
}

