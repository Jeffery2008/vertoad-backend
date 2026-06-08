<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;

final class SupportFeatureFlagsRouteContractTest extends TestCase
{
    public function testTask29SupportAndFeatureFlagRoutesAreRegistered(): void
    {
        foreach (
            [
                'VertoAD\\Http\\Action\\Support\\CreateSupportTicketAction',
                'VertoAD\\Http\\Action\\Support\\ListSupportTicketsAction',
                'VertoAD\\Http\\Action\\Support\\AddSupportTicketNoteAction',
                'VertoAD\\Http\\Action\\Support\\UpdateSupportTicketStatusAction',
                'VertoAD\\Http\\Action\\FeatureFlags\\CreateFeatureFlagAction',
                'VertoAD\\Http\\Action\\FeatureFlags\\ListFeatureFlagsAction',
                'VertoAD\\Http\\Action\\FeatureFlags\\EvaluateFeatureFlagAction',
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
                'POST /api/v1/support/tickets',
                'GET /api/v1/support/tickets',
                'POST /api/v1/support/tickets/{ticket_id}/notes',
                'POST /api/v1/support/tickets/{ticket_id}/status',
                'POST /api/v1/feature-flags',
                'GET /api/v1/feature-flags',
                'POST /api/v1/feature-flags/{flag_key}/evaluate',
            ] as $route
        ) {
            self::assertContains($route, $registered, $route . ' must be registered.');
        }
    }
}
