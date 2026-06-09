<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Service\SystemConfigService;

final class SystemConfigServiceTest extends TestCase
{
    public function testDefaultRevenueShareUsesConfiguredPublisherPercent(): void
    {
        $repository = new class implements SystemConfigRepositoryInterface {
            public function findLatestValue(string $configKey): ?array
            {
                TestCase::assertSame('billing.default_revenue_share', $configKey);

                return ['publisher_percent' => 72];
            }

            public function listLatestValues(): array
            {
                return ['billing.default_revenue_share' => ['publisher_percent' => 72]];
            }
        };

        $service = new SystemConfigService($repository);

        self::assertSame(72, $service->defaultPublisherRevenueSharePercent());
    }

    public function testDefaultRevenueShareFallsBackWhenConfigIsMissing(): void
    {
        $repository = new class implements SystemConfigRepositoryInterface {
            public function findLatestValue(string $configKey): ?array
            {
                return null;
            }

            public function listLatestValues(): array
            {
                return [];
            }
        };

        $service = new SystemConfigService($repository);

        self::assertSame(70, $service->defaultPublisherRevenueSharePercent());
    }

    public function testDefaultRevenueShareRejectsInvalidConfiguredPercent(): void
    {
        $repository = new class implements SystemConfigRepositoryInterface {
            public function findLatestValue(string $configKey): ?array
            {
                return ['publisher_percent' => 101];
            }

            public function listLatestValues(): array
            {
                return ['billing.default_revenue_share' => ['publisher_percent' => 101]];
            }
        };

        $service = new SystemConfigService($repository);

        $this->expectException(\UnexpectedValueException::class);
        $service->defaultPublisherRevenueSharePercent();
    }
}
