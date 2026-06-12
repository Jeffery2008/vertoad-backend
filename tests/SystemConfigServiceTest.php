<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
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

    public function testAttributionDefaultWindowUsesConfiguredSeconds(): void
    {
        $repository = new ArraySystemConfigRepository([
            'attribution.default_window_seconds' => ['seconds' => 7200],
        ]);

        $service = new SystemConfigService($repository);

        self::assertSame(7200, $service->attributionDefaultWindowSeconds());
        self::assertSame(['attribution.default_window_seconds'], $repository->queries);
    }

    public function testAttributionDefaultWindowFallsBackWhenConfigIsMissing(): void
    {
        $service = new SystemConfigService(new ArraySystemConfigRepository());

        self::assertSame(604800, $service->attributionDefaultWindowSeconds());
    }

    public function testAttributionDefaultWindowRejectsInvalidConfiguredSeconds(): void
    {
        foreach (
            [
                ['seconds' => 0],
                ['seconds' => -1],
                ['seconds' => '604800'],
                ['window_seconds' => 604800],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'attribution.default_window_seconds' => $value,
            ]));

            try {
                $service->attributionDefaultWindowSeconds();
                self::fail('Invalid attribution.default_window_seconds value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertSame('Invalid attribution.default_window_seconds seconds.', $exception->getMessage());
            }
        }
    }

    public function testRateLimitPolicyUsesConfiguredLimitAndWindow(): void
    {
        $repository = new ArraySystemConfigRepository([
            'security.rate_limit' => ['limit' => 120, 'window_seconds' => 45],
        ]);

        $policy = (new SystemConfigService($repository))->rateLimitPolicy();

        self::assertInstanceOf(RateLimitPolicy::class, $policy);
        self::assertSame(120, $policy->limit);
        self::assertSame(45, $policy->windowSeconds);
        self::assertSame(['security.rate_limit'], $repository->queries);
    }

    public function testRateLimitPolicyFallsBackWhenConfigIsMissing(): void
    {
        $policy = (new SystemConfigService(new ArraySystemConfigRepository()))->rateLimitPolicy();

        self::assertSame(60, $policy->limit);
        self::assertSame(60, $policy->windowSeconds);
    }

    public function testRateLimitPolicyRejectsInvalidConfiguredValues(): void
    {
        foreach (
            [
                ['limit' => 0, 'window_seconds' => 60],
                ['limit' => 60, 'window_seconds' => 0],
                ['limit' => '60', 'window_seconds' => 60],
                ['limit' => 60, 'window_seconds' => '60'],
                ['limit' => 60],
            ] as $value
        ) {
            $service = new SystemConfigService(new ArraySystemConfigRepository([
                'security.rate_limit' => $value,
            ]));

            try {
                $service->rateLimitPolicy();
                self::fail('Invalid security.rate_limit value must be rejected.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringStartsWith('Invalid security.rate_limit ', $exception->getMessage());
            }
        }
    }

    public function testMissingRuntimeConfigFailsWhenFallbacksAreDisabled(): void
    {
        $service = new SystemConfigService(new ArraySystemConfigRepository(), allowRuntimeFallbacks: false);

        try {
            $service->attributionDefaultWindowSeconds();
            self::fail('Production runtime config must not silently fall back when attribution config is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Missing required system config: attribution.default_window_seconds.',
                $exception->getMessage(),
            );
        }

        try {
            $service->rateLimitPolicy();
            self::fail('Production runtime config must not silently fall back when rate limit config is missing.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Missing required system config: security.rate_limit.', $exception->getMessage());
        }
    }

    public function testRepositoryFailuresAreRethrownWhenRuntimeFallbacksAreDisabled(): void
    {
        $service = new SystemConfigService(new FailingSystemConfigRepository(), allowRuntimeFallbacks: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config repository unavailable');

        $service->rateLimitPolicy();
    }
}

final class ArraySystemConfigRepository implements SystemConfigRepositoryInterface
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, array<string, mixed>> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public function findLatestValue(string $configKey): ?array
    {
        $this->queries[] = $configKey;

        return $this->values[$configKey] ?? null;
    }

    public function listLatestValues(): array
    {
        return $this->values;
    }
}

final class FailingSystemConfigRepository implements SystemConfigRepositoryInterface
{
    public function findLatestValue(string $configKey): ?array
    {
        throw new \RuntimeException('config repository unavailable');
    }

    public function listLatestValues(): array
    {
        throw new \RuntimeException('config repository unavailable');
    }
}
