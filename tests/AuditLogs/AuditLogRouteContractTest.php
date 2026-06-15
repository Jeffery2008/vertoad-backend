<?php

declare(strict_types=1);

namespace VertoAD\Tests\AuditLogs;

use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;

final class AuditLogRouteContractTest extends TestCase
{
    public function testAuditLogListRouteIsRegistered(): void
    {
        $app = AppFactory::create(dirname(__DIR__, 2));
        $registered = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $registered[] = $method . ' ' . $route->getPattern();
            }
        }

        self::assertContains('GET /api/v1/audit-logs', $registered);
    }
}
