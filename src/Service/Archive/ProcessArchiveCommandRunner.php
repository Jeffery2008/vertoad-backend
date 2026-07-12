<?php

declare(strict_types=1);

namespace VertoAD\Service\Archive;

final readonly class ProcessArchiveCommandRunner implements ArchiveCommandRunnerInterface
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        if ($command === [] || trim($command[0]) === '') {
            throw new \InvalidArgumentException('Archive command must include an executable.');
        }

        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Archive command timeout seconds must be positive.');
        }

        /** @var resource $stdinStream */
        $stdinStream = fopen('php://temp/maxmemory:67108864', 'w+b');
        /** @var resource $stdoutStream */
        $stdoutStream = fopen('php://temp/maxmemory:67108864', 'w+b');
        /** @var resource $stderrStream */
        $stderrStream = fopen('php://temp/maxmemory:67108864', 'w+b');
        fwrite($stdinStream, $stdin ?? '');
        rewind($stdinStream);

        $process = proc_open(array_map('strval', $command), [
            0 => $stdinStream,
            1 => $stdoutStream,
            2 => $stderrStream,
        ], $pipes);
        fclose($stdinStream);
        if (!is_resource($process)) {
            fclose($stdoutStream);
            fclose($stderrStream);

            throw new \RuntimeException('Unable to start archive command.');
        }

        $timedOut = false;
        $exitCode = -1;
        $started = microtime(true);
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if ((microtime(true) - $started) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }

            usleep(1_000);
        }

        $closedExitCode = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closedExitCode;
        }

        rewind($stdoutStream);
        rewind($stderrStream);
        $stdout = stream_get_contents($stdoutStream) ?: '';
        $stderr = stream_get_contents($stderrStream) ?: '';
        fclose($stdoutStream);
        fclose($stderrStream);

        if ($timedOut) {
            return new ArchiveCommandResult(
                124,
                $stdout,
                trim($stderr . "\nArchive command timed out."),
            );
        }

        return new ArchiveCommandResult($exitCode, $stdout, $stderr);
    }
}
