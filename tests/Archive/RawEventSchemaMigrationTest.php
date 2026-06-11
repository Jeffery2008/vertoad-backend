<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use PHPUnit\Framework\TestCase;

final class RawEventSchemaMigrationTest extends TestCase
{
    public function testRawEventUuidMatchesServingEventIdLength(): void
    {
        $migration = strtolower((string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/20260606134000_create_core_schema.php'));

        self::assertStringContainsString('event_uuid varchar(160) not null', $migration);
        self::assertStringNotContainsString('event_uuid char(36)', $migration);
    }
}
