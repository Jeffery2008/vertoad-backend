<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('redis-integration')]
#[Group('serving-load')]
final class ServingLoadAcceptanceTest extends TestCase
{
    public function testServeTrackAndClickMeetTheRealInfrastructureLoadGate(): void
    {
        if (getenv('VERTOAD_SERVING_LOAD_ACCEPTANCE') !== '1') {
            self::markTestSkipped('Set VERTOAD_SERVING_LOAD_ACCEPTANCE=1 to run the real serving load rehearsal.');
        }

        $options = ServingLoadOptions::fromArgv(['runner']);
        $report = (new ServingLoadScenario(dirname(__DIR__, 2), $options))->run();

        self::assertTrue($report['passed'] ?? false, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        self::assertSame(0, $report['cleanup']['redis_keys_after'] ?? null);
        self::assertSame(0, $report['cleanup']['database_after'] ?? null);
    }
}
