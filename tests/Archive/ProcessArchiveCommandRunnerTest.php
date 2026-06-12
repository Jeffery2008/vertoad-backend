<?php

declare(strict_types=1);

namespace VertoAD\Tests\Archive;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\Archive\ProcessArchiveCommandRunner;

final class ProcessArchiveCommandRunnerTest extends TestCase
{
    public function testRunsCommandWithStdinAndCapturesOutput(): void
    {
        $runner = new ProcessArchiveCommandRunner();
        $result = $runner->run([PHP_BINARY, '-r', 'echo strtoupper(stream_get_contents(STDIN));'], 'ok', 10);

        self::assertSame(0, $result->exitCode);
        self::assertSame('OK', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testCapturesNonZeroExitCodeAndStderr(): void
    {
        $runner = new ProcessArchiveCommandRunner();
        $result = $runner->run([PHP_BINARY, '-r', 'fwrite(STDERR, "bad"); exit(7);'], null, 10);

        self::assertSame(7, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame('bad', $result->stderr);
    }

    public function testRejectsInvalidCommandAndTimeout(): void
    {
        $runner = new ProcessArchiveCommandRunner();

        try {
            $runner->run([''], null, 10);
            self::fail('Expected executable validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Archive command must include an executable.', $exception->getMessage());
        }

        try {
            $runner->run([PHP_BINARY, '-r', 'echo "unused";'], null, 0);
            self::fail('Expected timeout validation failure.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Archive command timeout seconds must be positive.', $exception->getMessage());
        }
    }

    public function testReportsProcessStartFailure(): void
    {
        $runner = new ProcessArchiveCommandRunner();
        set_error_handler(static fn (): bool => true);

        try {
            $runner->run(['Z:/vertoad/missing/archive-command.exe'], null, 10);
            self::fail('Expected process start failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unable to start archive command.', $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testTerminatesLongRunningCommandAfterTimeout(): void
    {
        $runner = new ProcessArchiveCommandRunner();
        $result = $runner->run([PHP_BINARY, '-r', 'sleep(2);'], null, 1);

        self::assertSame(124, $result->exitCode);
        self::assertStringContainsString('Archive command timed out.', $result->stderr);
    }

    public function testClosesProcessPipesWhenCommandExecutionThrows(): void
    {
        $runner = new ProcessArchiveCommandRunner();
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $this->expectException(\ErrorException::class);
            $this->expectExceptionMessage('Broken pipe');

            $runner->run([PHP_BINARY, '-r', 'exit(0);'], str_repeat('x', 1024 * 1024 * 16), 10);
        } finally {
            restore_error_handler();
        }
    }
}
