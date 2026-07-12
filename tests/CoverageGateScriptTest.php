<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;

define('VERTOAD_COVERAGE_GATE_TESTING', true);
require_once dirname(__DIR__) . '/scripts/coverage-gate.php';

final class CoverageGateScriptTest extends TestCase
{
    public function testBuildsExplicitBlockerWhenNoCoverageDriverIsAvailable(): void
    {
        $diagnostics = coverageDriverDiagnostics(
            loadedExtensions: [],
            sapi: 'cli',
            phpdbgPath: null,
        );

        self::assertFalse($diagnostics['available']);
        self::assertNull($diagnostics['runnerPrefix']);
        self::assertStringContainsString('Coverage driver blocker', $diagnostics['message']);
        self::assertStringContainsString('Xdebug', $diagnostics['message']);
        self::assertStringContainsString('PCOV', $diagnostics['message']);
        self::assertStringContainsString('phpdbg', $diagnostics['message']);
        self::assertStringContainsString('Detected SAPI: cli', $diagnostics['message']);
        self::assertStringContainsString('Detected coverage extensions: none', $diagnostics['message']);
        self::assertStringContainsString('Detected phpdbg: not found', $diagnostics['message']);
        self::assertStringContainsString('Running PHPUnit without coverage', $diagnostics['message']);
    }

    public function testBuildsPhpdbgCoverageRunnerWhenPhpdbgIsAvailable(): void
    {
        $diagnostics = coverageDriverDiagnostics(
            loadedExtensions: [],
            sapi: 'cli',
            phpdbgPath: '/usr/bin/phpdbg',
        );

        self::assertTrue($diagnostics['available']);
        self::assertSame(['/usr/bin/phpdbg', '-qrr'], $diagnostics['runnerPrefix']);
        self::assertSame('', $diagnostics['message']);
    }

    public function testCoverageRunnerExcludesOptInIntegrationsAndFailsOnSkippedTests(): void
    {
        $runner = coverageRunner(
            'vendor/phpunit/phpunit/phpunit',
            'build/coverage/clover.xml',
            [
                'available' => true,
                'runnerPrefix' => [PHP_BINARY],
                'message' => '',
                'sapi' => 'cli',
                'coverageExtensions' => ['xdebug'],
                'phpdbgPath' => null,
            ],
        );

        self::assertSame([
            PHP_BINARY,
            'vendor/phpunit/phpunit/phpunit',
            '--exclude-group',
            'redis-integration',
            '--exclude-group',
            'mysql-install-rehearsal',
            '--exclude-group',
            'external-tools-integration',
            '--fail-on-skipped',
            '--coverage-clover',
            'build/coverage/clover.xml',
            '--coverage-text',
        ], $runner);
        self::assertSame([
            '--exclude-group',
            'redis-integration',
            '--exclude-group',
            'mysql-install-rehearsal',
            '--exclude-group',
            'external-tools-integration',
            '--fail-on-skipped',
        ], phpunitGateArguments());
    }

    public function testBuildsFallbackBlockerAfterDetectedDriverCannotProduceCoverage(): void
    {
        $diagnostics = coverageDriverDiagnostics(
            loadedExtensions: ['Xdebug'],
            sapi: 'cli',
            phpdbgPath: null,
        );

        $message = \coverageFallbackBlockerMessage($diagnostics);

        self::assertStringContainsString('Coverage driver blocker', $message);
        self::assertStringContainsString('Detected SAPI: cli', $message);
        self::assertStringContainsString('Detected coverage extensions: xdebug', $message);
        self::assertStringContainsString('Detected phpdbg: not found', $message);
        self::assertStringContainsString('Running PHPUnit without coverage', $message);
    }
}
