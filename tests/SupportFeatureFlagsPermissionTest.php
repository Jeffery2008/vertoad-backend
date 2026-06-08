<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\Permission;
use VertoAD\Service\PermissionInventory;

final class SupportFeatureFlagsPermissionTest extends TestCase
{
    public function testTask29PermissionInventoryIncludesSupportAndFeatureFlagBoundaryPermissions(): void
    {
        $codes = array_column((new PermissionInventory())->all(), 'code');

        foreach (
            [
                'support.ticket.read.own',
                'support.ticket.read.assigned',
                'support.ticket.read.escalated',
                'support.ticket.write.own',
                'support.ticket.note.internal.platform',
                'support.ticket.status.update.platform',
                'feature_flag.read.platform',
                'feature_flag.write.platform',
                'feature_flag.publish.platform',
            ] as $permission
        ) {
            self::assertContains($permission, $codes, $permission . ' must be in the RBAC inventory.');
        }
    }

    public function testFeatureFlagPublishPlatformPermissionIsAvailableForRouteGuards(): void
    {
        self::assertTrue(
            defined(Permission::class . '::FeatureFlagPublishPlatform'),
            'Permission::FeatureFlagPublishPlatform must exist for broad flag publish route guards.',
        );

        self::assertContains(constant(Permission::class . '::FeatureFlagPublishPlatform'), Permission::all());
    }
}
