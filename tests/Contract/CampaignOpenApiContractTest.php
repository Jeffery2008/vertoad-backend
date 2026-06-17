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
}
