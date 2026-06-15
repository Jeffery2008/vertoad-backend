<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\TestCase;

final class MysqlPartitionRehearsalCommandTest extends TestCase
{
    public function testComposerScriptTargetsTheRehearsalEntryPoint(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            'php scripts/mysql-partition-rehearsal.php --environment=testing --reset-database',
            $composer['scripts']['test:mysql-partition-rehearsal'] ?? null,
        );
    }
}
