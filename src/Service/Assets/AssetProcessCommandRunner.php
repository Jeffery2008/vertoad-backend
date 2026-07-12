<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use RuntimeException;
use Throwable;
use VertoAD\Service\Archive\ArchiveCommandResult;
use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;

final readonly class AssetProcessCommandRunner implements ArchiveCommandRunnerInterface
{
    public function __construct(
        private int $maxStdoutBytes = 33_554_432,
        private int $maxStderrBytes = 1_048_576,
        private int $pollIntervalMicroseconds = 10_000,
    ) {
        if ($this->maxStdoutBytes <= 0 || $this->maxStderrBytes <= 0 || $this->pollIntervalMicroseconds <= 0) {
            throw new \InvalidArgumentException('Asset process output limits and poll interval must be positive.');
        }
    }

    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        if ($command === []) {
            throw new \InvalidArgumentException('Asset process command must include an executable.');
        }
        try {
            $command = array_map('strval', $command);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to start asset process command.', previous: $exception);
        }
        if (trim($command[0]) === '') {
            throw new \InvalidArgumentException('Asset process command must include an executable.');
        }
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Asset process timeout seconds must be positive.');
        }

        $streams = $this->temporaryStreams();
        [$inputStream, $stdoutStream, $stderrStream] = $streams;
        $process = null;

        try {
            $this->writeInput($inputStream, $stdin ?? '');
            rewind($inputStream);
            $descriptorSpec = [0 => $inputStream, 1 => $stdoutStream, 2 => $stderrStream];
            try {
                $process = @proc_open($command, $descriptorSpec, $pipes, options: ['bypass_shell' => true]);
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to start asset process command.', previous: $exception);
            }
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start asset process command.');
            }

            $startedAt = microtime(true);
            $exitCode = -1;
            $forcedResult = null;
            while (true) {
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new RuntimeException('Unable to inspect asset process command status.');
                }
                if ($this->outputExceeded($stdoutStream, $stderrStream)) {
                    proc_terminate($process);
                    $forcedResult = new ArchiveCommandResult(
                        125,
                        '',
                        'Asset process output exceeded its configured limit.',
                    );
                    break;
                }
                if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                    proc_terminate($process);
                    $forcedResult = new ArchiveCommandResult(124, '', 'Asset process command timed out.');
                    break;
                }
                if (!$status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }

                usleep($this->pollIntervalMicroseconds);
            }

            $closedExitCode = proc_close($process);
            $process = null;
            if ($forcedResult instanceof ArchiveCommandResult) {
                return $forcedResult;
            }
            if ($this->outputExceeded($stdoutStream, $stderrStream)) {
                return new ArchiveCommandResult(
                    125,
                    '',
                    'Asset process output exceeded its configured limit.',
                );
            }
            if ($exitCode < 0 && $closedExitCode >= 0) {
                $exitCode = $closedExitCode;
            }

            return new ArchiveCommandResult(
                $exitCode,
                $this->readOutput($stdoutStream),
                $this->readOutput($stderrStream),
            );
        } catch (Throwable $exception) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }

            throw $exception;
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    /** @return array{0:resource, 1:resource, 2:resource} */
    private function temporaryStreams(): array
    {
        $streams = [];
        foreach ([0, 1, 2] as $_) {
            $stream = tmpfile();
            if (!is_resource($stream)) {
                foreach ($streams as $opened) {
                    fclose($opened);
                }

                throw new RuntimeException('Unable to create temporary streams for asset process command.');
            }
            $streams[] = $stream;
        }

        return $streams;
    }

    /** @param resource $stream */
    private function writeInput($stream, string $input): void
    {
        $offset = 0;
        $length = strlen($input);
        while ($offset < $length) {
            $written = fwrite($stream, substr($input, $offset, 65_536));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to buffer asset process command input.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stdout @param resource $stderr */
    private function outputExceeded($stdout, $stderr): bool
    {
        return $this->streamSize($stdout) > $this->maxStdoutBytes
            || $this->streamSize($stderr) > $this->maxStderrBytes;
    }

    /** @param resource $stream */
    private function streamSize($stream): int
    {
        $metadata = fstat($stream);
        if (!is_array($metadata) || !isset($metadata['size']) || !is_int($metadata['size'])) {
            throw new RuntimeException('Unable to inspect asset process command output.');
        }

        return $metadata['size'];
    }

    /** @param resource $stream */
    private function readOutput($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read asset process command output.');
        }

        return $contents;
    }
}
