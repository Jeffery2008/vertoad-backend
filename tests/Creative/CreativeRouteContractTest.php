<?php

declare(strict_types=1);

namespace VertoAD\Tests\Creative;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\PermissionInventory;

final class CreativeRouteContractTest extends TestCase
{
    public function testCreativeRoutesDeclareExpectedPermissions(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/config/routes.php');

        foreach (
            [
                ['get', '/api/v1/creative/templates', 'creative.template.read.own', 'permission'],
                ['post', '/api/v1/creative/designs', 'creative.design.write.own', 'permission'],
                ['get', '/api/v1/creative/designs/{design_id}/versions', 'creative.design.read.own', 'permission'],
                ['post', '/api/v1/creative/designs/{design_id}/versions', 'creative.design.write.own', 'permission'],
            ] as [$method, $route, $permission, $factory]
        ) {
            $pattern = preg_quote("\$app->{$method}('" . $route . "'", '/')
                . '(?s:.{0,260})'
                . preg_quote("->add($" . $factory . "('" . $permission . "'))", '/');

            self::assertTrue(
                preg_match('/' . $pattern . '/', $routes) === 1,
                strtoupper($method) . ' ' . $route . ' must enforce ' . $permission . '.',
            );
        }

        self::assertStringContainsString(
            "\$app->post('/api/v1/creative/templates', CreateCreativeTemplateAction::class)",
            $routes,
        );
        self::assertStringContainsString(
            '->add(CreativeTemplateWritePermissionMiddleware::class)',
            $routes,
            'POST /api/v1/creative/templates must use body-aware template write permissions.',
        );
    }

    public function testPermissionInventoryContainsCreativeTemplateAndDesignCodes(): void
    {
        $codes = array_column((new PermissionInventory())->all(), 'code');

        foreach (
            [
                'creative.template.read.own',
                'creative.template.write.own',
                'creative.template.manage.platform',
                'creative.design.read.own',
                'creative.design.write.own',
            ] as $permission
        ) {
            self::assertContains($permission, $codes);
        }
    }

    public function testBootstrapSuperAdminSeedIncludesCreativePermissions(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/db/init-super-admin.sql');

        foreach (
            [
                'creative.template.read.own',
                'creative.template.write.own',
                'creative.template.manage.platform',
                'creative.design.read.own',
                'creative.design.write.own',
            ] as $permission
        ) {
            self::assertStringContainsString("'" . $permission . "'", $script);
        }
    }
}
