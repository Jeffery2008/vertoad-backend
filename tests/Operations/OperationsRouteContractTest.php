<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;

final class OperationsRouteContractTest extends TestCase
{
    public function testTask28OperationsRoutesAreRegistered(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $registered = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }

        foreach (
            [
                'GET /api/v1/operations/summary',
                'GET /api/v1/operations/errors',
                'GET /api/v1/operations/request-correlations',
                'GET /api/v1/operations/request-correlations/{request_id}',
                'GET /api/v1/operations/errors/{error_id}/raw-context',
                'POST /api/v1/operations/ip-geo/lookup',
                'GET /api/v1/operations/config/versions',
                'POST /api/v1/operations/config/versions',
                'POST /api/v1/operations/config/versions/{version_id}/rollback',
                'GET /api/v1/operations/webhooks/deliveries',
                'POST /api/v1/operations/webhooks/deliveries/{delivery_id}/retry',
            ] as $route
        ) {
            self::assertContains($route, $registered, $route . ' must be registered.');
        }
    }
}
