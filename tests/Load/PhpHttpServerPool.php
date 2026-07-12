<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

final class PhpHttpServerPool
{
    /** @var list<array{process:resource,stdin:resource,log:string,url:string}> */
    private array $servers = [];
    /** @var list<string> */
    private array $baseUrls = [];
    private bool $stopped = false;

    /** @param array<string, string> $environment */
    private function __construct(
        private readonly string $rootPath,
        private readonly string $workspace,
        private readonly array $environment,
    ) {
    }

    /** @param array<string, string> $environment */
    public static function start(string $rootPath, int $workers, array $environment): self
    {
        if ($workers < 1) {
            throw new \InvalidArgumentException('At least one HTTP worker is required.');
        }
        $rootPath = realpath($rootPath) ?: $rootPath;
        if (!is_file($rootPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php')) {
            throw new \InvalidArgumentException('The backend public entrypoint is unavailable.');
        }

        $workspace = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR
            . 'vertoad-serving-load-' . bin2hex(random_bytes(8));
        if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) {
            throw new \RuntimeException('Unable to create the serving load worker workspace.');
        }
        $pool = new self($rootPath, $workspace, $environment);

        try {
            for ($index = 0; $index < $workers; ++$index) {
                $pool->startWorker($index);
            }

            return $pool;
        } catch (\Throwable $exception) {
            try {
                $pool->stop();
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    /** @return list<string> */
    public function baseUrls(): array
    {
        return $this->baseUrls;
    }

    /** @return list<string> */
    public function logTails(): array
    {
        $tails = [];
        foreach ($this->servers as $server) {
            $content = is_file($server['log']) ? file_get_contents($server['log']) : false;
            if (!is_string($content) || trim($content) === '') {
                continue;
            }
            $tails[] = substr($content, -4_000);
        }

        return $tails;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $failure = null;

        foreach ($this->servers as $server) {
            try {
                if (is_resource($server['stdin'])) {
                    fclose($server['stdin']);
                }
                $status = proc_get_status($server['process']);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($server['process']);
                    $deadline = microtime(true) + 3.0;
                    do {
                        usleep(25_000);
                        $status = proc_get_status($server['process']);
                    } while (($status['running'] ?? false) === true && microtime(true) < $deadline);
                    if (($status['running'] ?? false) === true) {
                        proc_terminate($server['process'], 9);
                    }
                }
                proc_close($server['process']);
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }
        $this->servers = [];
        $this->baseUrls = [];

        foreach (glob($this->workspace . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path) && !unlink($path)) {
                $failure ??= new \RuntimeException('Unable to remove a serving load worker log.');
            }
        }
        if (is_dir($this->workspace) && !rmdir($this->workspace)) {
            $failure ??= new \RuntimeException('Unable to remove the serving load worker workspace.');
        }

        if ($failure !== null) {
            throw new \RuntimeException('Unable to stop the serving load worker pool cleanly.', previous: $failure);
        }
    }

    private function startWorker(int $index): void
    {
        $port = $this->availablePort();
        $url = 'http://127.0.0.1:' . $port;
        $log = $this->workspace . DIRECTORY_SEPARATOR . 'worker-' . $index . '.log';
        $public = $this->rootPath . DIRECTORY_SEPARATOR . 'public';
        $entrypoint = $public . DIRECTORY_SEPARATOR . 'index.php';
        $command = [
            PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            '-d',
            'display_errors=0',
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $public,
            $entrypoint,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $log, 'ab'],
            2 => ['file', $log, 'ab'],
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $this->rootPath,
            $this->environment,
            ['bypass_shell' => true],
        );
        if (!is_resource($process) || !isset($pipes[0]) || !is_resource($pipes[0])) {
            throw new \RuntimeException('Unable to start a serving load HTTP worker.');
        }

        $server = ['process' => $process, 'stdin' => $pipes[0], 'log' => $log, 'url' => $url];
        $this->servers[] = $server;
        $this->baseUrls[] = $url;
        $this->awaitReady($server);
    }

    /** @param array{process:resource,stdin:resource,log:string,url:string} $server */
    private function awaitReady(array $server): void
    {
        $deadline = microtime(true) + 60.0;
        $lastStatus = 0;
        do {
            $status = proc_get_status($server['process']);
            if (($status['running'] ?? false) !== true) {
                throw new \RuntimeException('A serving load HTTP worker exited during startup: ' . $this->logTail($server['log']));
            }

            $handle = curl_init($server['url'] . '/api/v1/ads/serve');
            if ($handle === false) {
                throw new \RuntimeException('Unable to initialize the worker readiness request.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT_MS => 1_000,
                CURLOPT_TIMEOUT_MS => 20_000,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-Request-Id: serving-load-worker-probe-' . bin2hex(random_bytes(8)),
                ],
            ]);
            $body = curl_exec($handle);
            $lastStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($lastStatus === 422 && is_string($body) && str_contains($body, 'invalid_request')) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException(
            'A serving load HTTP worker did not become ready (last status ' . $lastStatus . '): '
            . $this->logTail($server['log']),
        );
    }

    private function availablePort(): int
    {
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) {
            throw new \RuntimeException('Unable to reserve a loopback port for the load worker.');
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $separator = is_string($name) ? strrpos($name, ':') : false;
        $port = $separator === false ? 0 : (int) substr($name, $separator + 1);
        if ($port < 1 || $port > 65_535) {
            throw new \RuntimeException('The reserved loopback port was invalid.');
        }

        return $port;
    }

    private function logTail(string $path): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($content) || trim($content) === '') {
            return '[no worker log output]';
        }
        $content = preg_replace('/\s+/', ' ', trim(substr($content, -4_000))) ?? '';

        return substr($content, 0, 1_000);
    }
}
