<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Service\Operations\Backup\ProcessMysqlBackupRunner;
use VertoAD\Service\Operations\Backup\UnavailableMysqlBackupRunner;

final class ProcessMysqlBackupRunnerTest extends TestCase
{
    private string $tempDirectory;
    private string $fixture;
    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-mysql-runner-' . bin2hex(random_bytes(5));
        mkdir($this->tempDirectory, 0700, true);
        $this->fixture = $this->tempDirectory . DIRECTORY_SEPARATOR . 'mysql-fixture.php';
        file_put_contents($this->fixture, <<<'PHP'
<?php
$mode = $argv[1] ?? '';
$arguments = array_slice($argv, 2);
$capture = getenv('MYSQL_FIXTURE_CAPTURE');
if (is_string($capture) && $capture !== '') {
    file_put_contents($capture, json_encode([
        'mode' => $mode,
        'arguments' => $arguments,
        'password' => getenv('MYSQL_PWD'),
        'stdin' => stream_get_contents(STDIN),
    ], JSON_THROW_ON_ERROR));
}
$behavior = getenv('MYSQL_FIXTURE_BEHAVIOR') ?: 'success';
if ($behavior === 'sleep') {
    sleep(2);
}
if ($behavior === 'fail') {
    fwrite(STDERR, "fixture failure\n");
    exit(7);
}
if ($behavior === 'fail-stdout') {
    fwrite(STDOUT, "stdout failure\n");
    exit(8);
}
if ($mode === 'dump' && $behavior !== 'empty') {
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--result-file=')) {
            file_put_contents(substr($argument, strlen('--result-file=')), "CREATE TABLE restored (id INT);\n");
        }
    }
}
PHP);
        foreach (['MYSQL_FIXTURE_CAPTURE', 'MYSQL_FIXTURE_BEHAVIOR'] as $name) {
            $this->previousEnvironment[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        foreach (glob($this->tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->tempDirectory);
    }

    public function testRunsDumpAndRestoreWithoutPuttingPasswordInArguments(): void
    {
        $capture = $this->tempDirectory . DIRECTORY_SEPARATOR . 'capture.json';
        putenv('MYSQL_FIXTURE_CAPTURE=' . $capture);
        putenv('MYSQL_FIXTURE_BEHAVIOR=success');
        $runner = $this->runner();
        $dump = $this->tempDirectory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'dump.sql';

        $runner->dump($dump);
        $dumpCapture = json_decode((string) file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString('CREATE TABLE restored', (string) file_get_contents($dump));
        self::assertSame('dump', $dumpCapture['mode']);
        self::assertSame('secret-password', $dumpCapture['password']);
        self::assertStringNotContainsString('secret-password', implode(' ', $dumpCapture['arguments']));
        self::assertContains('--single-transaction', $dumpCapture['arguments']);
        self::assertContains('--set-gtid-purged=OFF', $dumpCapture['arguments']);

        $runner->restore($dump);
        $restoreCapture = json_decode((string) file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('restore', $restoreCapture['mode']);
        self::assertStringContainsString('CREATE TABLE restored', $restoreCapture['stdin']);
        self::assertContains('--binary-mode', $restoreCapture['arguments']);
    }

    public function testRejectsInvalidConfigurationTimeoutAndSources(): void
    {
        foreach (
            [
                ['', 'mysql', 10, $this->database(), 'binaries'],
                ['mysqldump', '', 10, $this->database(), 'binaries'],
                ['mysqldump', 'mysql', 0, $this->database(), 'timeout'],
                ['mysqldump', 'mysql', 10, array_merge($this->database(), ['host' => '']), 'host'],
                ['mysqldump', 'mysql', 10, array_merge($this->database(), ['database' => '']), 'database'],
                ['mysqldump', 'mysql', 10, array_merge($this->database(), ['username' => '']), 'username'],
            ] as [$dump, $restore, $timeout, $database, $expected]
        ) {
            try {
                new ProcessMysqlBackupRunner($database, $dump, $restore, timeoutSeconds: $timeout);
                self::fail('Expected invalid MySQL runner configuration.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($expected, strtolower($exception->getMessage()));
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not readable');
        $this->runner()->restore($this->tempDirectory . DIRECTORY_SEPARATOR . 'missing.sql');
    }

    public function testReportsCommandFailuresEmptyDumpsTimeoutAndDirectoryFailures(): void
    {
        $runner = $this->runner(timeout: 1);
        foreach (
            [
                ['fail', 'fixture failure'],
                ['fail-stdout', 'stdout failure'],
                ['empty', 'empty dump'],
                ['sleep', 'timed out'],
            ] as [$behavior, $expected]
        ) {
            putenv('MYSQL_FIXTURE_BEHAVIOR=' . $behavior);
            try {
                $runner->dump($this->tempDirectory . DIRECTORY_SEPARATOR . $behavior . '.sql');
                self::fail('Expected MySQL command failure.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($expected, strtolower($exception->getMessage()));
            }
        }

        putenv('MYSQL_FIXTURE_BEHAVIOR=success');
        $file = $this->tempDirectory . DIRECTORY_SEPARATOR . 'not-a-directory';
        file_put_contents($file, 'x');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backup directory');
        $runner->dump($file . DIRECTORY_SEPARATOR . 'dump.sql');
    }

    public function testUnavailableRunnerFailsBothOperationsExplicitly(): void
    {
        $runner = new UnavailableMysqlBackupRunner('mysql backup unavailable');
        foreach (['dump', 'restore'] as $operation) {
            try {
                $runner->{$operation}($this->tempDirectory . DIRECTORY_SEPARATOR . 'dump.sql');
                self::fail('Expected unavailable MySQL backup runner to fail.');
            } catch (RuntimeException $exception) {
                self::assertSame('mysql backup unavailable', $exception->getMessage());
            }
        }
    }

    public function testReportsProcessStartupFailure(): void
    {
        $runner = new ProcessMysqlBackupRunner(
            $this->database(),
            PHP_BINARY,
            PHP_BINARY,
            [$this->fixture, 'dump'],
            [$this->fixture, 'restore'],
            5,
            static function (array $command, array $descriptors, array &$pipes): false {
                $pipes = [];

                return false;
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to start MySQL backup command');
        $runner->dump($this->tempDirectory . DIRECTORY_SEPARATOR . 'startup-failure.sql');
    }

    private function runner(int $timeout = 5): ProcessMysqlBackupRunner
    {
        return new ProcessMysqlBackupRunner(
            $this->database(),
            PHP_BINARY,
            PHP_BINARY,
            [$this->fixture, 'dump'],
            [$this->fixture, 'restore'],
            $timeout,
        );
    }

    /** @return array<string, int|string> */
    private function database(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'vertoad_test',
            'username' => 'vertoad',
            'password' => 'secret-password',
            'charset' => 'utf8mb4',
        ];
    }
}
