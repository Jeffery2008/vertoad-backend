<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\PublisherSiteVerificationService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\SystemConfigService;

final class AppContainerTest extends TestCase
{
    public function testContainerProvidesDatabaseAndSystemConfigBoundary(): void
    {
        $container = AppFactory::create()->getContainer();

        self::assertNotNull($container);
        self::assertInstanceOf(Connection::class, $container->get(Connection::class));
        self::assertInstanceOf(PublisherSiteRepositoryInterface::class, $container->get(PublisherSiteRepositoryInterface::class));
        self::assertInstanceOf(PublisherSiteVerificationService::class, $container->get(PublisherSiteVerificationService::class));
        self::assertInstanceOf(AdSlotRepositoryInterface::class, $container->get(AdSlotRepositoryInterface::class));
        self::assertInstanceOf(AdSlotSetupService::class, $container->get(AdSlotSetupService::class));
        self::assertInstanceOf(AuditLogRepositoryInterface::class, $container->get(AuditLogRepositoryInterface::class));
        self::assertInstanceOf(AuditLogService::class, $container->get(AuditLogService::class));
        self::assertInstanceOf(PointsLedgerRepositoryInterface::class, $container->get(PointsLedgerRepositoryInterface::class));
        self::assertInstanceOf(PointsLedgerService::class, $container->get(PointsLedgerService::class));
        self::assertInstanceOf(RechargeKeyPlaintextCipherInterface::class, $container->get(RechargeKeyPlaintextCipherInterface::class));
        self::assertInstanceOf(RechargeKeyRepositoryInterface::class, $container->get(RechargeKeyRepositoryInterface::class));
        self::assertInstanceOf(RechargeKeyService::class, $container->get(RechargeKeyService::class));
        self::assertInstanceOf(SystemConfigRepositoryInterface::class, $container->get(SystemConfigRepositoryInterface::class));
        self::assertInstanceOf(SystemConfigService::class, $container->get(SystemConfigService::class));
    }
}
