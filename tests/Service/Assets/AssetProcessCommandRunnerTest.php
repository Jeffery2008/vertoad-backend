<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets {
    /** @param array<int, mixed> $descriptorSpec */
    function proc_open(
        array|string $command,
        array $descriptorSpec,
        mixed &$pipes,
        ?string $cwd = null,
        ?array $envVars = null,
        ?array $options = null,
    ): mixed {
        $native = \VertoAD\Tests\Service\Assets\AssetProcessNative::class;
        if ($native::$procOpenMode === 'throw') {
            throw new \RuntimeException('injected process startup exception');
        }
        if ($native::$procOpenMode === 'false') {
            return false;
        }

        return \proc_open($command, $descriptorSpec, $pipes, $cwd, $envVars, $options);
    }

    /** @param resource $process @return array<string, int|bool|string>|false */
    function proc_get_status($process): array|false
    {
        $native = \VertoAD\Tests\Service\Assets\AssetProcessNative::class;
        if ($native::$procStatusMode === 'false') {
            $native::$procStatusMode = 'normal';

            return false;
        }
        $status = \proc_get_status($process);
        if ($native::$procStatusMode === 'stopped_zero') {
            $status['running'] = false;
            $status['exitcode'] = 0;
        } elseif ($native::$procStatusMode === 'stopped_negative') {
            $status['running'] = false;
            $status['exitcode'] = -1;
        }

        return $status;
    }

    function tmpfile(): mixed
    {
        $native = \VertoAD\Tests\Service\Assets\AssetProcessNative::class;
        ++$native::$tmpfileCalls;
        if ($native::$failTmpfileCall === $native::$tmpfileCalls) {
            return false;
        }

        return \tmpfile();
    }

    /** @param resource $stream @return array<string, int>|false */
    function fstat($stream): array|false
    {
        $native = \VertoAD\Tests\Service\Assets\AssetProcessNative::class;
        ++$native::$fstatCalls;
        if ($native::$fstatMode === 'invalid') {
            $native::$fstatMode = 'normal';

            return false;
        }
        $metadata = \fstat($stream);
        if ($native::$fstatMode === 'post_close_overflow' && $native::$fstatCalls === 3) {
            $metadata['size'] = 10;
        }

        return $metadata;
    }

    /** @param resource $stream */
    function fwrite($stream, string $data, ?int $length = null): int|false
    {
        if (\VertoAD\Tests\Service\Assets\AssetProcessNative::$failWrite) {
            \VertoAD\Tests\Service\Assets\AssetProcessNative::$failWrite = false;

            return false;
        }

        return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
    }

    /** @param resource $stream */
    function stream_get_contents($stream): string|false
    {
        $native = \VertoAD\Tests\Service\Assets\AssetProcessNative::class;
        if ($native::$throwOnRead) {
            $native::$throwOnRead = false;
            throw new \RuntimeException('injected stream failure');
        }
        if ($native::$failRead) {
            $native::$failRead = false;

            return false;
        }

        return \stream_get_contents($stream);
    }
}

namespace VertoAD\Tests\Service\Assets {

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Service\Assets\AssetProcessCommandRunner;

final class AssetProcessCommandRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        AssetProcessNative::reset();
    }

    public function testStreamsStdinAndCollectsBothOutputChannels(): void
    {
        $runner = new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000);
        $result = $runner->run($this->phpCommand(
            '$input = stream_get_contents(STDIN); fwrite(STDOUT, strtoupper($input)); fwrite(STDERR, "notice");',
        ), 'private-video', 5);

        self::assertSame(0, $result->exitCode);
        self::assertSame('PRIVATE-VIDEO', $result->stdout);
        self::assertSame('notice', $result->stderr);
    }

    public function testAcceptsNullStdinAndNonStringCommandArguments(): void
    {
        $result = (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run([
            PHP_BINARY,
            '-r',
            new StringableCommandArgument('fwrite(STDOUT, "ok");'),
        ], null, 5);

        self::assertSame(0, $result->exitCode);
        self::assertSame('ok', $result->stdout);
    }

    public function testExecutesAnExecutableFromAPathContainingSpacesWithoutShellQuoting(): void
    {
        $directory = null;

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                    . 'vertoad process path ' . bin2hex(random_bytes(5));
                self::assertTrue(mkdir($directory, 0700, true));
                $source = (getenv('WINDIR') ?: 'C:\\Windows') . '\\System32\\where.exe';
                $executable = $directory . DIRECTORY_SEPARATOR . 'where runner.exe';
                self::assertTrue(copy($source, $executable));
                $command = [$executable, 'cmd.exe'];
            } else {
                $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                    . 'vertoad process path ' . bin2hex(random_bytes(5));
                self::assertTrue(mkdir($directory, 0700, true));
                $executable = $directory . DIRECTORY_SEPARATOR . 'php runner';
                self::assertTrue(copy(PHP_BINARY, $executable));
                self::assertTrue(chmod($executable, 0700));
                $command = [$executable, '-n', '-r', 'fwrite(STDOUT, "posix-path-ok");'];
            }

            $result = (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run($command, null, 5);
            self::assertSame(0, $result->exitCode);
            if (PHP_OS_FAMILY === 'Windows') {
                self::assertStringEndsWith('\\cmd.exe', strtolower(trim($result->stdout)));
            } else {
                self::assertSame('posix-path-ok', trim($result->stdout));
            }
        } finally {
            if ($directory !== null) {
                @unlink($executable);
                @rmdir($directory);
            }
        }
    }

    public function testStopsCommandsThatExceedStdoutOrStderrLimits(): void
    {
        $stdout = (new AssetProcessCommandRunner(4, 64, 1_000))->run(
            $this->phpCommand('fwrite(STDOUT, "12345");'),
            null,
            5,
        );
        self::assertSame(125, $stdout->exitCode);
        self::assertSame('', $stdout->stdout);
        self::assertSame('Asset process output exceeded its configured limit.', $stdout->stderr);

        $stderr = (new AssetProcessCommandRunner(64, 4, 1_000))->run(
            $this->phpCommand('fwrite(STDERR, "12345");'),
            null,
            5,
        );
        self::assertSame(125, $stderr->exitCode);
    }

    public function testStopsTimedOutCommand(): void
    {
        $result = (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run(
            $this->phpCommand('sleep(3);'),
            null,
            1,
        );

        self::assertSame(124, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame('Asset process command timed out.', $result->stderr);
    }

    public function testRejectsTruncatedBufferedInput(): void
    {
        AssetProcessNative::$failWrite = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to buffer asset process command input.');
        (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run(
            $this->phpCommand('fwrite(STDOUT, "closed");'),
            'input',
            5,
        );
    }

    public function testCleansUpAndRethrowsLoopFailures(): void
    {
        AssetProcessNative::$throwOnRead = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('injected stream failure');
        (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run(
            $this->phpCommand('sleep(1);'),
            'input',
            5,
        );
    }

    public function testRejectsInvalidConfigurationCommandAndTimeout(): void
    {
        foreach ([[0, 1, 1], [1, 0, 1], [1, 1, 0]] as [$stdout, $stderr, $poll]) {
            try {
                new AssetProcessCommandRunner($stdout, $stderr, $poll);
                self::fail('Expected invalid process limits.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset process output limits and poll interval must be positive.', $exception->getMessage());
            }
        }

        $runner = new AssetProcessCommandRunner();
        foreach ([[], [''], ['  ']] as $command) {
            try {
                $runner->run($command, null, 1);
                self::fail('Expected missing executable.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset process command must include an executable.', $exception->getMessage());
            }
        }

        try {
            $runner->run([PHP_BINARY], null, 0);
            self::fail('Expected invalid timeout.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Asset process timeout seconds must be positive.', $exception->getMessage());
        }
    }

    public function testReportsProcessStartupFailures(): void
    {
        $runner = new AssetProcessCommandRunner();
        try {
            $runner->run(['Z:\\missing-vertoad-process.exe'], null, 1);
            self::fail('Expected process startup failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to start asset process command.', $exception->getMessage());
        }

        try {
            $runner->run([new ThrowingCommandArgument()], null, 1);
            self::fail('Expected command conversion failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to start asset process command.', $exception->getMessage());
            self::assertSame('conversion failed', $exception->getPrevious()?->getMessage());
        }
    }

    public function testReportsInjectedStartupAndStatusFailuresAndClosesRunningProcesses(): void
    {
        foreach (['throw', 'false'] as $mode) {
            AssetProcessNative::$procOpenMode = $mode;
            try {
                (new AssetProcessCommandRunner())->run($this->phpCommand('exit(0);'), null, 5);
                self::fail('Expected injected process startup failure.');
            } catch (RuntimeException $exception) {
                self::assertSame('Unable to start asset process command.', $exception->getMessage());
            } finally {
                AssetProcessNative::$procOpenMode = 'normal';
            }
        }

        AssetProcessNative::$procStatusMode = 'false';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to inspect asset process command status.');
        (new AssetProcessCommandRunner())->run($this->phpCommand('sleep(2);'), null, 5);
    }

    public function testReportsTemporaryStreamMetadataAndOutputReadFailures(): void
    {
        AssetProcessNative::$failTmpfileCall = 2;
        try {
            (new AssetProcessCommandRunner())->run($this->phpCommand('exit(0);'), null, 5);
            self::fail('Expected temporary stream creation failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to create temporary streams for asset process command.', $exception->getMessage());
        }

        AssetProcessNative::reset();
        AssetProcessNative::$fstatMode = 'invalid';
        try {
            (new AssetProcessCommandRunner())->run($this->phpCommand('sleep(2);'), null, 5);
            self::fail('Expected process output metadata failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to inspect asset process command output.', $exception->getMessage());
        }

        AssetProcessNative::reset();
        AssetProcessNative::$failRead = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read asset process command output.');
        (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run(
            $this->phpCommand('fwrite(STDOUT, "ok");'),
            null,
            5,
        );
    }

    public function testUsesPostCloseLimitCheckAndClosedExitCodeFallback(): void
    {
        AssetProcessNative::$procStatusMode = 'stopped_zero';
        AssetProcessNative::$fstatMode = 'post_close_overflow';
        $overflow = (new AssetProcessCommandRunner(4, 4, 1_000))->run(
            $this->phpCommand('exit(0);'),
            null,
            5,
        );
        self::assertSame(125, $overflow->exitCode);
        self::assertSame('Asset process output exceeded its configured limit.', $overflow->stderr);

        AssetProcessNative::reset();
        AssetProcessNative::$procStatusMode = 'stopped_negative';
        $fallback = (new AssetProcessCommandRunner(pollIntervalMicroseconds: 1_000))->run(
            $this->phpCommand('exit(7);'),
            null,
            5,
        );
        self::assertSame(7, $fallback->exitCode);
    }

    /** @return list<string> */
    private function phpCommand(string $script): array
    {
        return [PHP_BINARY, '-r', $script];
    }
}

final class AssetProcessNative
{
    public static string $procOpenMode = 'normal';
    public static string $procStatusMode = 'normal';
    public static int $tmpfileCalls = 0;
    public static ?int $failTmpfileCall = null;
    public static int $fstatCalls = 0;
    public static string $fstatMode = 'normal';
    public static bool $failWrite = false;
    public static bool $throwOnRead = false;
    public static bool $failRead = false;

    public static function reset(): void
    {
        self::$procOpenMode = 'normal';
        self::$procStatusMode = 'normal';
        self::$tmpfileCalls = 0;
        self::$failTmpfileCall = null;
        self::$fstatCalls = 0;
        self::$fstatMode = 'normal';
        self::$failWrite = false;
        self::$throwOnRead = false;
        self::$failRead = false;
    }
}

final readonly class StringableCommandArgument
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class ThrowingCommandArgument
{
    public function __toString(): string
    {
        throw new RuntimeException('conversion failed');
    }
}
}
