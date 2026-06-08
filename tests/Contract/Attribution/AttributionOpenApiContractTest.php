<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract\Attribution;

use PHPUnit\Framework\TestCase;

final class AttributionOpenApiContractTest extends TestCase
{
    public function testAttributionPathsDocumentPixelServerApiAndSchemas(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');

        self::assertStringContainsString('/api/v1/attribution/pixel:', $openApi);
        self::assertStringContainsString('/api/v1/attribution/conversions:', $openApi);

        foreach (['event_id', 'viewer_id', 'conversion_name', 'value_points', 'occurred_at', 'window_seconds'] as $field) {
            self::assertStringContainsString($field . ':', $openApi);
        }

        foreach (['ConversionRequest:', 'ConversionData:', 'AttributionData:'] as $schema) {
            self::assertStringContainsString($schema, $openApi);
        }

        self::assertStringContainsString('last_click', $openApi);
        self::assertStringContainsString('browser_pixel', $openApi);
        self::assertStringContainsString('server_api', $openApi);
    }
}
