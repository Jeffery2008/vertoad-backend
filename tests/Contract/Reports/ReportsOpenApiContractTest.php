<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract\Reports;

use PHPUnit\Framework\TestCase;

final class ReportsOpenApiContractTest extends TestCase
{
    public function testDashboardReportPathDocumentsFiltersAndSchemas(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/openapi.yaml');
        $dashboardPath = $this->schemaBlock($openApi, '  /api/v1/reports/dashboard:', '  /api/v1/oauth/clients:');
        $reportRange = $this->schemaBlock($openApi, '    ReportRange:', '    ReportTotals:');
        $reportTotals = $this->schemaBlock($openApi, '    ReportTotals:', '    ReportSeriesPoint:');
        $reportSeriesPoint = $this->schemaBlock($openApi, '    ReportSeriesPoint:', '    ReportDimensions:');

        self::assertStringContainsString('/api/v1/reports/dashboard:', $openApi);
        foreach (['portal', 'organization_id', 'campaign_id', 'site_id', 'slot_id', 'from', 'to', 'granularity'] as $parameter) {
            self::assertStringContainsString('name: ' . $parameter, $dashboardPath);
        }

        foreach (['ReportDashboardData:', 'ReportRange:', 'ReportTotals:', 'ReportSeriesPoint:', 'ReportDimensions:', 'ReportDimensionBucket:'] as $schema) {
            self::assertStringContainsString($schema, $openApi);
        }

        self::assertStringContainsString('- totals', $openApi);
        self::assertStringContainsString('- dimensions', $openApi);
        self::assertStringContainsString('risk_score:', $openApi);
        self::assertMatchesRegularExpression('/name: portal\s+in: query\s+required: false\s+schema:\s+type: string\s+enum:\s+- admin\s+- advertiser\s+- publisher/s', $dashboardPath);
        self::assertMatchesRegularExpression('/name: from\s+in: query\s+required: false\s+schema:\s+type: string\s+format: date-time\s+pattern: /s', $dashboardPath);
        self::assertMatchesRegularExpression('/name: to\s+in: query\s+required: false\s+schema:\s+type: string\s+format: date-time\s+pattern: /s', $dashboardPath);
        self::assertMatchesRegularExpression('/from:\s+type: string\s+format: date-time\s+pattern: /s', $reportRange);
        self::assertMatchesRegularExpression('/to:\s+type: string\s+format: date-time\s+pattern: /s', $reportRange);
        self::assertStringNotContainsString('- spend_cny', $reportTotals);
        self::assertStringNotContainsString('- revenue_cny', $reportTotals);
        self::assertMatchesRegularExpression('/date:\s+description:.*oneOf:\s+- type: string\s+format: date\s+pattern: .*\s+- type: string\s+format: date-time\s+pattern: /s', $reportSeriesPoint);
    }

    private function schemaBlock(string $openApi, string $start, string $end): string
    {
        $startOffset = strpos($openApi, $start);
        self::assertNotFalse($startOffset, $start);
        $endOffset = strpos($openApi, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end);

        return substr($openApi, $startOffset, $endOffset - $startOffset);
    }
}
