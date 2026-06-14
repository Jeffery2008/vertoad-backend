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
        self::assertStringContainsString('BearerAuth: []', $this->pathBlock($openApi, '/api/v1/attribution/conversions'));
        self::assertStringContainsString('attribution.conversion.write.own', $this->pathBlock($openApi, '/api/v1/attribution/conversions'));
        self::assertStringContainsString('"401":', $this->pathBlock($openApi, '/api/v1/attribution/conversions'));
        self::assertStringContainsString('"403":', $this->pathBlock($openApi, '/api/v1/attribution/conversions'));
        self::assertStringContainsString('security: []', $this->pathBlock($openApi, '/api/v1/attribution/pixel'));
        self::assertStringContainsString('organization_id:', $this->schemaBlock($openApi, 'ConversionData'));
        self::assertStringContainsString('oauth_client_id:', $this->schemaBlock($openApi, 'ConversionData'));
        self::assertStringContainsString('recorded_by_user_id:', $this->schemaBlock($openApi, 'ConversionData'));
        self::assertStringContainsString('occurred_at:', $this->schemaBlock($openApi, 'ConversionData'));
        self::assertStringContainsString('Business occurrence time used for ROI and CVR reporting buckets.', $this->schemaBlock($openApi, 'ConversionData'));
    }

    private function pathBlock(string $openApi, string $path): string
    {
        $pattern = '/^  ' . preg_quote($path, '/') . ':\R(?<block>(?: {4}\S.*\R| {6,}.*\R)*)/m';
        self::assertSame(1, preg_match($pattern, $openApi, $matches), $path . ' must be documented.');

        return $matches['block'];
    }

    private function schemaBlock(string $openApi, string $schema): string
    {
        $pattern = '/^    ' . preg_quote($schema, '/') . ':\R(?<block>(?: {6}\S.*\R| {8,}.*\R)*)/m';
        self::assertSame(1, preg_match($pattern, $openApi, $matches), $schema . ' must be documented.');

        return $matches['block'];
    }
}
