<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Service\PermissionInventory;
use VertoAD\Service\PermissionMatcher;

final class PermissionInventoryTest extends TestCase
{
    public function testInventoryCoversCurrentBackendModules(): void
    {
        $permissions = Permission::all();

        foreach ([
            Permission::SystemConfigRead,
            Permission::SystemConfigWrite,
            Permission::AuditLogsRead,
            Permission::LedgerRead,
            Permission::LedgerAdjust,
            Permission::RechargeKeysGenerate,
            Permission::RechargeKeysRedeem,
            Permission::RechargeKeysViewPlaintext,
            Permission::PublisherSitesManage,
            Permission::PublisherSlotsManage,
            Permission::CronStatusRead,
        ] as $permission) {
            self::assertContains($permission, $permissions);
        }
    }

    public function testPermissionMatcherAllowsExactAndWildcardGrants(): void
    {
        $matcher = new PermissionMatcher();

        self::assertTrue($matcher->allows([Permission::SystemConfigRead], Permission::SystemConfigRead));
        self::assertTrue($matcher->allows(['publisher.*'], Permission::PublisherSlotsManage));
        self::assertTrue($matcher->allows(['*'], Permission::AuditLogsRead));
        self::assertFalse($matcher->allows([Permission::PublisherSitesRead], Permission::PublisherSlotsManage));
        self::assertFalse($matcher->allows(['publisher.sites.*'], Permission::PublisherSlotsManage));
    }

    public function testOrganizationMemberPermissionsUseCurrentApiCodes(): void
    {
        $codes = array_column((new PermissionInventory())->all(), 'code');

        self::assertContains(Permission::OrganizationMembersRead, $codes);
        self::assertContains(Permission::OrganizationMembersManage, $codes);
        self::assertNotContains('org.member.invite.own', $codes);
        self::assertNotContains('org.member.remove.own', $codes);
        self::assertNotContains('org.member.manage.platform', $codes);
    }

    public function testBootstrapSuperAdminSeedIncludesEveryCurrentPermissionCode(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/db/init-super-admin.sql');

        foreach (array_column((new PermissionInventory())->all(), 'code') as $code) {
            self::assertStringContainsString("'" . $code . "'", $script, $code . ' must be seeded for the initial super admin role.');
        }
    }
}
