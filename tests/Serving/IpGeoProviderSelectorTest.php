<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;

final class IpGeoProviderSelectorTest extends TestCase
{
    public function testSelectChoosesProviderDeterministicallyWithinRegionAndFallsBackToGlobal(): void
    {
        $policy = IpGeoProviderPolicy::fromArray([
            'providers' => [
                [
                    'id' => 'cn-primary',
                    'endpoint_template' => 'https://cn-primary.example/geo/{ip}',
                    'regions' => ['CN'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'country_code'],
                ],
                [
                    'id' => 'cn-heavy',
                    'endpoint_template' => 'https://cn-heavy.example/geo/{ip}',
                    'regions' => ['CN'],
                    'weight' => 5,
                    'fields' => ['country_code' => 'country_code'],
                ],
                [
                    'id' => 'global-provider',
                    'endpoint_template' => 'https://global.example/geo/{ip}',
                    'regions' => ['global'],
                    'weight' => 1,
                    'fields' => ['country_code' => 'country_code'],
                ],
            ],
        ]);
        $selector = new IpGeoProviderSelector($policy);

        $first = $selector->select('CN', '198.51.100.10');
        $second = $selector->select('CN', '198.51.100.10');

        self::assertSame($first->id, $second->id);
        self::assertContains($first->id, ['cn-primary', 'cn-heavy']);
        self::assertSame('global-provider', $selector->select('US', '198.51.100.10')->id);
    }

    public function testSelectRejectsMissingProvidersForRegion(): void
    {
        $selector = new IpGeoProviderSelector(new IpGeoProviderPolicy(enabled: true, providers: []));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No IP geo provider is configured for region.');

        $selector->select('CN', '203.0.113.60');
    }

    public function testSlotRejectsNonPositiveWeights(): void
    {
        $selector = new IpGeoProviderSelector(new IpGeoProviderPolicy(enabled: true, providers: []));
        $slot = new \ReflectionMethod(IpGeoProviderSelector::class, 'slot');
        $slot->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IP geo provider weights must be positive.');

        $slot->invoke($selector, 'CN', '203.0.113.60', 0);
    }
}
