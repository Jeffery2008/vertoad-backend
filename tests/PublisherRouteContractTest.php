<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;

final class PublisherRouteContractTest extends TestCase
{
    public function testPublisherSiteAndSlotRoutesAreRegistered(): void
    {
        foreach (
            [
                'VertoAD\\Http\\Action\\Publisher\\CreatePublisherSiteAction',
                'VertoAD\\Http\\Action\\Publisher\\ListPublisherSitesAction',
                'VertoAD\\Http\\Action\\Publisher\\GetPublisherSiteVerificationChallengeAction',
                'VertoAD\\Http\\Action\\Publisher\\VerifyPublisherSiteAction',
                'VertoAD\\Http\\Action\\Publisher\\ListPublisherAdSlotPresetsAction',
                'VertoAD\\Http\\Action\\Publisher\\CreatePublisherAdSlotAction',
                'VertoAD\\Http\\Action\\Publisher\\ListPublisherAdSlotsAction',
            ] as $actionClass
        ) {
            self::assertTrue(class_exists($actionClass), $actionClass . ' must exist.');
        }

        $app = AppFactory::create(dirname(__DIR__));
        $registered = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }

        foreach (
            [
                'POST /api/v1/publisher/sites',
                'GET /api/v1/publisher/sites',
                'GET /api/v1/publisher/sites/{site_id}/verification-challenge',
                'POST /api/v1/publisher/sites/{site_id}/verify',
                'GET /api/v1/publisher/ad-slot-presets',
                'POST /api/v1/publisher/sites/{site_id}/slots',
                'GET /api/v1/publisher/sites/{site_id}/slots',
            ] as $route
        ) {
            self::assertContains($route, $registered, $route . ' must be registered.');
        }
    }

    public function testPublisherRoutesDeclareFineGrainedPermissionMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/config/routes.php');

        self::assertTrue(str_contains($routes, 'RequirePermissionMiddleware'), 'Publisher routes must use permission middleware.');
        foreach (
            [
                'publisher.site.read.own',
                'publisher.site.write.own',
                'publisher.site.verify.own',
                'publisher.slot.read.own',
                'publisher.slot.write.own',
            ] as $permission
        ) {
            self::assertTrue(str_contains($routes, $permission), $permission . ' must be enforced by routes.php.');
        }
    }
}
