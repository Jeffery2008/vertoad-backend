<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProcessMysqlBackupRunner implements MysqlBackupRunnerInterface
{
    /**
     * @param array<string, mixed> $database
     * @param list<string> $dumpPrefixArguments
     * @param list<string> $restorePrefixArguments
     * @param Closure(array<int, string>, array<int, mixed>, array<int, resource>&, ?string, array<string, string>, array<string, bool>):mixed|null $processOpener
     */
    public function __construct(
        private array $database,
        private string $dumpBinary = 'mysqldump',
        private string $restoreBinary = 'mysql',
        private array $dumpPrefixArguments = [],
        private array $restorePrefixArguments = [],
        private int $timeoutSeconds = 900,
        private ?Closure $processOpener = null,
    ) {
        if (trim($dumpBinary) === '' || trim($restoreBinary) === '') {
            throw new InvalidArgumentException('MySQL backup binaries are required.');
        }
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('MySQL backup command timeout must be positive.');
        }
        foreach (['host', 'database', 'username'] as $field) {
            if (trim((string) ($database[$field] ?? '')) === '') {
                throw new InvalidArgumentException('MySQL backup database ' . $field . ' is required.');
            }
        }
    }

    public function dump(string $destinationPath): void
    {
        $directory = dirname($destinationPath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create MySQL backup directory.');
        }

        $command = array_merge(
            [trim($this->dumpBinary)],
            array_values(array_map('strval', $this->dumpPrefixArguments)),
            $this->connectionArguments(),
            [
                '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob',
                '--set-gtid-purged=OFF', '--result-file=' . $destinationPath,
                (string) $this->database['database'],
            ],
        );
        $this->run($command, null, 'MySQL backup');
        if (!is_file($destinationPath) || filesize($destinationPath) === 0) {
            throw new RuntimeException('MySQL backup command produced an empty dump.');
        }
    }

    public function restore(string $sourcePath): void
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new InvalidArgumentException('MySQL restore source is not readable.');
        }
        $command = array_merge(
            [trim($this->restoreBinary)],
            array_values(array_map('strval', $this->restorePrefixArguments)),
            $this->connectionArguments(),
            ['--binary-mode', (string) $this->database['database']],
        );
        $this->run($command, $sourcePath, 'MySQL restore');
    }

    /** @return list<string> */
    private function connectionArguments(): array
    {
        return [
            '--host=' . trim((string) $this->database['host']),
            '--port=' . (int) ($this->database['port'] ?? 3306),
            '--user=' . trim((string) $this->database['username']),
            '--default-character-set=' . trim((string) ($this->database['charset'] ?? 'utf8mb4')),
            '--protocol=TCP',
        ];
    }

    /** @param non-empty-list<string> $command */
    private function run(array $command, ?string $stdinPath, string $operation): void
    {
        $descriptors = [
            0 => $stdinPath === null ? ['pipe', 'r'] : ['file', $stdinPath, 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['MYSQL_PWD'] = (string) ($this->database['password'] ?? '');
        $pipes = [];
        $process = $this->processOpener === null
            ? proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true])
            : ($this->processOpener)($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start ' . $operation . ' command.');
        }

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        try {
            if ($stdinPath === null && isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $started = microtime(true);
            while (true) {
                $status = proc_get_status($process);
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }
                if ((microtime(true) - $started) > $this->timeoutSeconds) {
                    proc_terminate($process);
                    throw new RuntimeException($operation . ' command timed out.');
                }
                usleep(20_000);
            }
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $closedExitCode = proc_close($process);
            if ($exitCode < 0) {
                $exitCode = $closedExitCode;
            }
        }
        if ($exitCode !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            throw new RuntimeException($operation . ' command failed: ' . substr($message, 0, 2000));
        }
    }
}
