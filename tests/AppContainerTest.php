<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\SystemConfigService;

final class AppContainerTest extends TestCase
{
    public function testContainerProvidesDatabaseAndSystemConfigBoundary(): void
    {
        $container = AppFactory::create()->getContainer();

        self::assertNotNull($container);
        self::assertInstanceOf(Connection::class, $container->get(Connection::class));
        self::assertInstanceOf(AuditLogRepositoryInterface::class, $container->get(AuditLogRepositoryInterface::class));
        self::assertInstanceOf(AuditLogService::class, $container->get(AuditLogService::class));
        self::assertInstanceOf(SystemConfigRepositoryInterface::class, $container->get(SystemConfigRepositoryInterface::class));
        self::assertInstanceOf(SystemConfigService::class, $container->get(SystemConfigService::class));
    }
}
