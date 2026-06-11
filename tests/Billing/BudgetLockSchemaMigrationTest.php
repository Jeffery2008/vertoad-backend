<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use PHPUnit\Framework\TestCase;

final class BudgetLockSchemaMigrationTest extends TestCase
{
    public function testCampaignBudgetMigrationDefinesOrganizationAndCampaignLocks(): void
    {
        $migrationSql = preg_replace(
            '/\s+/',
            ' ',
            strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260607235000_create_campaign_budget_primitives.php')),
        ) ?? '';

        foreach ([
            'create table organization_budget_locks (',
            'organization_id bigint unsigned not null',
            'updated_at datetime not null default current_timestamp on update current_timestamp',
            'primary key (organization_id)',
            'constraint fk_organization_budget_locks_organization foreign key (organization_id) references organizations (id) on delete cascade',
            'create table campaign_budget_locks (',
            'campaign_id bigint unsigned not null',
            'primary key (organization_id, campaign_id)',
            'constraint fk_campaign_budget_locks_organization foreign key (organization_id) references organizations (id) on delete cascade',
            'constraint fk_campaign_budget_locks_campaign foreign key (campaign_id) references campaigns (id) on delete cascade',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $migrationSql);
        }
    }
}
