<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CampaignOpenApiContractTest extends TestCase
{
    public function testCampaignUpsertRequestStatusEnumOnlyContainsDeliveryStatuses(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        $enum = $openApi['components']['schemas']['CampaignUpsertRequest']['properties']['status']['enum'] ?? null;

        self::assertSame(['draft', 'active', 'paused', 'archived'], $enum);
        foreach (['pending_ai', 'ai_reviewing', 'needs_human', 'approved', 'rejected'] as $reviewOnlyStatus) {
            self::assertNotContains($reviewOnlyStatus, $enum);
        }
    }

    public function testCampaignTargetingDocumentsCanonicalDevicesAndTimezoneAwareWindows(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        $schema = $openApi['components']['schemas']['CampaignTargeting'] ?? null;
        self::assertIsArray($schema);
        self::assertSame(
            ['desktop', 'mobile', 'tablet'],
            $schema['properties']['devices']['items']['enum'] ?? null,
        );
        $window = $schema['properties']['time_windows']['items'] ?? null;
        self::assertIsArray($window);
        self::assertSame(['day_of_week', 'start', 'end', 'timezone'], $window['required'] ?? null);
        self::assertSame(0, $window['properties']['day_of_week']['minimum'] ?? null);
        self::assertSame(6, $window['properties']['day_of_week']['maximum'] ?? null);
        self::assertSame(
            '^(?:[01][0-9]|2[0-3]):[0-5][0-9]$',
            $window['properties']['start']['pattern'] ?? null,
        );
        self::assertSame(
            '^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)$',
            $window['properties']['end']['pattern'] ?? null,
        );
        self::assertStringContainsString(
            'IANA timezone',
            (string) ($window['properties']['timezone']['description'] ?? ''),
        );
        self::assertStringContainsString(
            'crosses into the following local day',
            (string) ($schema['properties']['time_windows']['description'] ?? ''),
        );
    }
}
