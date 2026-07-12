<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

final class ConcurrentHttpLoadClient
{
    private const MAX_DIAGNOSTICS = 5;

    public function __construct(private readonly int $timeoutMs)
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The serving load runner requires the PHP curl extension.');
        }
        if ($this->timeoutMs < 100) {
            throw new \InvalidArgumentException('HTTP timeout must be at least 100 ms.');
        }
    }

    /**
     * @param list<array{url:string,method:string,headers:list<string>,body?:string,label:string}> $requests
     * @param callable(array{status:int,headers:array<string,string>,body:string}, array<string, mixed>): mixed $validator
     */
    public function run(
        string $stage,
        array $requests,
        int $concurrency,
        ServingLoadThreshold $threshold,
        callable $validator,
    ): ServingLoadBatchResult {
        if ($requests === []) {
            throw new \InvalidArgumentException('A load stage requires at least one request.');
        }
        if ($concurrency < 1 || $concurrency > count($requests)) {
            throw new \InvalidArgumentException('Load concurrency must be between 1 and request count.');
        }

        $multi = curl_multi_init();
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
        }

        $next = 0;
        $active = [];
        $latencies = [];
        $outputs = [];
        $successes = 0;
        $statusCounts = [];
        $diagnostics = [];
        $startedAt = hrtime(true);

        $enqueue = function () use (&$next, &$active, $requests, $multi): void {
            $request = $requests[$next];
            $handle = curl_init($request['url']);
            if ($handle === false) {
                throw new \RuntimeException('Unable to initialize a curl request.');
            }
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => min(3_000, $this->timeoutMs),
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($request['method']),
                CURLOPT_HTTPHEADER => $request['headers'],
            ];
            if (array_key_exists('body', $request)) {
                $options[CURLOPT_POSTFIELDS] = $request['body'];
            }
            curl_setopt_array($handle, $options);
            curl_multi_add_handle($multi, $handle);
            $active[spl_object_id($handle)] = [
                'handle' => $handle,
                'index' => $next,
                'started_at' => hrtime(true),
                'request' => $request,
            ];
            ++$next;
        };

        try {
            while ($next < count($requests) && count($active) < $concurrency) {
                $enqueue();
            }

            do {
                do {
                    $multiStatus = curl_multi_exec($multi, $running);
                } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);
                if ($multiStatus !== CURLM_OK) {
                    throw new \RuntimeException('curl_multi_exec failed with code ' . $multiStatus . '.');
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $key = spl_object_id($handle);
                    $context = $active[$key] ?? null;
                    if (!is_array($context)) {
                        throw new \RuntimeException('A completed curl handle was not tracked.');
                    }
                    unset($active[$key]);

                    $raw = curl_multi_getcontent($handle);
                    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    $statusCounts[(string) $status] = ($statusCounts[(string) $status] ?? 0) + 1;
                    $reportedSeconds = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
                    $elapsedMs = $reportedSeconds > 0.0
                        ? $reportedSeconds * 1_000
                        : (hrtime(true) - $context['started_at']) / 1_000_000;
                    $latencies[] = $elapsedMs;

                    $curlError = curl_error($handle);
                    $curlCode = (int) ($info['result'] ?? CURLE_OK);
                    try {
                        if ($curlCode !== CURLE_OK) {
                            throw new \RuntimeException('curl error ' . $curlCode . ': ' . $curlError);
                        }
                        $response = $this->response(is_string($raw) ? $raw : '', $handle, $status);
                        $outputs[$context['index']] = $validator($response, $context['request']);
                        ++$successes;
                    } catch (\Throwable $exception) {
                        $outputs[$context['index']] = null;
                        if (count($diagnostics) < self::MAX_DIAGNOSTICS) {
                            $diagnostics[] = [
                                'request' => (int) $context['index'],
                                'label' => $this->diagnostic((string) $context['request']['label']),
                                'status' => $status,
                                'error' => $this->diagnostic($exception->getMessage()),
                                'body' => $this->diagnostic($this->bodyFromRaw(is_string($raw) ? $raw : '', $handle)),
                            ];
                        }
                    } finally {
                        curl_multi_remove_handle($multi, $handle);
                        curl_close($handle);
                    }

                    if ($next < count($requests)) {
                        $enqueue();
                    }
                }

                if ($active !== []) {
                    $selected = curl_multi_select($multi, 1.0);
                    if ($selected === -1) {
                        usleep(1_000);
                    }
                }
            } while ($active !== [] || $next < count($requests) || $running > 0);
        } finally {
            foreach ($active as $context) {
                curl_multi_remove_handle($multi, $context['handle']);
                curl_close($context['handle']);
            }
            curl_multi_close($multi);
        }

        ksort($outputs);
        ksort($statusCounts, SORT_NATURAL);
        $duration = max(0.000001, (hrtime(true) - $startedAt) / 1_000_000_000);
        $metrics = ServingLoadStageMetrics::fromSamples(
            stage: $stage,
            requests: count($requests),
            successes: $successes,
            durationSeconds: $duration,
            latenciesMs: $latencies,
            statusCounts: $statusCounts,
            diagnostics: $diagnostics,
            threshold: $threshold,
        );

        return new ServingLoadBatchResult($metrics, array_values($outputs));
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private function response(string $raw, \CurlHandle $handle, int $status): array
    {
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headerBlock = substr($raw, 0, $headerSize);

        return [
            'status' => $status,
            'headers' => $this->headers($headerBlock),
            'body' => substr($raw, $headerSize),
        ];
    }

    /** @return array<string, string> */
    private function headers(string $headerBlock): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\n|\r/', $headerBlock) ?: [] as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function bodyFromRaw(string $raw, \CurlHandle $handle): string
    {
        return substr($raw, (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE));
    }

    private function diagnostic(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return substr($value, 0, 400);
    }
}
