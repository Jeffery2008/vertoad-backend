<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\Permission;
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
}
