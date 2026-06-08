<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract\Reports;

use PHPUnit\Framework\TestCase;

final class ReportsOpenApiContractTest extends TestCase
{
    public function testDashboardReportPathDocumentsFiltersAndSchemas(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');

        self::assertStringContainsString('/api/v1/reports/dashboard:', $openApi);
        foreach (['portal', 'organization_id', 'campaign_id', 'site_id', 'slot_id', 'from', 'to', 'granularity'] as $parameter) {
            self::assertStringContainsString('name: ' . $parameter, $openApi);
        }

        foreach (['ReportDashboardData:', 'ReportRange:', 'ReportTotals:', 'ReportSeriesPoint:', 'ReportDimensions:', 'ReportDimensionBucket:'] as $schema) {
            self::assertStringContainsString($schema, $openApi);
        }

        self::assertStringContainsString('- totals', $openApi);
        self::assertStringContainsString('- dimensions', $openApi);
        self::assertStringContainsString('risk_score:', $openApi);
    }
}
