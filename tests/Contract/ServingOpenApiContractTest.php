<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ServingOpenApiContractTest extends TestCase
{
    public function testTrackRequestDocumentsImpressionAndVideoTelemetryEvents(): void
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        $schema = $openApi['components']['schemas']['AdTrackRequest'] ?? null;
        self::assertIsArray($schema);

        $allEventTypes = [
            'impression',
            'video_start',
            'video_25',
            'video_50',
            'video_75',
            'video_complete',
            'video_mute',
            'video_pause',
        ];
        $videoEventTypes = [
            'video_start',
            'video_25',
            'video_50',
            'video_75',
            'video_complete',
            'video_mute',
            'video_pause',
        ];

        self::assertSame(
            $allEventTypes,
            $schema['properties']['event_type']['enum'] ?? null,
        );
        self::assertSame('impression', $schema['properties']['event_type']['default'] ?? null);

        $branches = $schema['oneOf'] ?? null;
        self::assertIsArray($branches);
        self::assertCount(2, $branches);

        $impressionBranch = null;
        $videoBranch = null;
        foreach ($branches as $branch) {
            self::assertIsArray($branch);
            $eventTypes = $branch['properties']['event_type']['enum'] ?? null;
            if ($eventTypes === ['impression']) {
                $impressionBranch = $branch;
                continue;
            }

            if ($eventTypes === $videoEventTypes) {
                $videoBranch = $branch;
            }
        }

        self::assertIsArray($impressionBranch);
        self::assertRequiredContains(
            ['decision_id', 'viewer_id', 'event_id', 'visible_ratio', 'visible_ms'],
            $impressionBranch,
        );

        self::assertIsArray($videoBranch);
        self::assertRequiredContains(
            ['decision_id', 'viewer_id', 'event_id', 'event_type'],
            $videoBranch,
        );
        self::assertNotContains('impression', $videoBranch['properties']['event_type']['enum']);
    }

    /**
     * @param list<string> $expectedRequired
     * @param array<string, mixed> $schema
     */
    private static function assertRequiredContains(array $expectedRequired, array $schema): void
    {
        $required = $schema['required'] ?? null;
        self::assertIsArray($required);

        foreach ($expectedRequired as $property) {
            self::assertContains($property, $required);
        }
    }
}
