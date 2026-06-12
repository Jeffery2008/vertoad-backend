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

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(array_map('strval', $command), $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start archive command.');
        }

        try {
            fwrite($pipes[0], $stdin ?? '');
            fclose($pipes[0]);

            $started = microtime(true);
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                if ((microtime(true) - $started) > $timeoutSeconds) {
                    proc_terminate($process);
                    fclose($pipes[1]);
                    fclose($pipes[2]);

                    return new ArchiveCommandResult(124, '', 'Archive command timed out.');
                }

                usleep(10_000);
            }

            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            return new ArchiveCommandResult($exitCode, $stdout, $stderr);
        } catch (\Throwable $exception) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);

            throw $exception;
        }
    }
}
