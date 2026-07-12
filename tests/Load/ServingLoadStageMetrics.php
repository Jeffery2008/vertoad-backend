<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

final readonly class ServingLoadStageMetrics
{
    /**
     * @param array<string, int> $statusCounts
     * @param list<array<string, int|string>> $diagnostics
     * @param list<string> $violations
     */
    private function __construct(
        public string $stage,
        public int $requests,
        public int $successes,
        public int $errors,
        public float $durationSeconds,
        public float $throughputRps,
        public float $errorRate,
        public float $p50Ms,
        public float $p95Ms,
        public float $p99Ms,
        public float $maxMs,
        public array $statusCounts,
        public array $diagnostics,
        public ServingLoadThreshold $threshold,
        public array $violations,
    ) {
    }

    /**
     * @param list<float> $latenciesMs
     * @param array<string, int> $statusCounts
     * @param list<array<string, int|string>> $diagnostics
     */
    public static function fromSamples(
        string $stage,
        int $requests,
        int $successes,
        float $durationSeconds,
        array $latenciesMs,
        array $statusCounts,
        array $diagnostics,
        ServingLoadThreshold $threshold,
    ): self {
        if ($requests < 1 || count($latenciesMs) !== $requests) {
            throw new \InvalidArgumentException('Every load request must have one latency sample.');
        }
        if ($successes < 0 || $successes > $requests || $durationSeconds <= 0.0) {
            throw new \InvalidArgumentException('Load result counts and duration are invalid.');
        }

        sort($latenciesMs, SORT_NUMERIC);
        $errors = $requests - $successes;
        $throughput = $successes / $durationSeconds;
        $errorRate = $errors / $requests;
        $p95 = self::percentile($latenciesMs, 0.95);
        $violations = [];
        if ($p95 > $threshold->maxP95Ms) {
            $violations[] = sprintf('p95 %.2f ms exceeds %.2f ms', $p95, $threshold->maxP95Ms);
        }
        if ($errorRate > $threshold->maxErrorRate) {
            $violations[] = sprintf('error rate %.4f exceeds %.4f', $errorRate, $threshold->maxErrorRate);
        }
        if ($throughput < $threshold->minThroughputRps) {
            $violations[] = sprintf('throughput %.2f rps is below %.2f rps', $throughput, $threshold->minThroughputRps);
        }

        return new self(
            stage: trim($stage),
            requests: $requests,
            successes: $successes,
            errors: $errors,
            durationSeconds: $durationSeconds,
            throughputRps: $throughput,
            errorRate: $errorRate,
            p50Ms: self::percentile($latenciesMs, 0.50),
            p95Ms: $p95,
            p99Ms: self::percentile($latenciesMs, 0.99),
            maxMs: max($latenciesMs),
            statusCounts: $statusCounts,
            diagnostics: $diagnostics,
            threshold: $threshold,
            violations: $violations,
        );
    }

    public function passed(): bool
    {
        return $this->violations === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'requests' => $this->requests,
            'successes' => $this->successes,
            'errors' => $this->errors,
            'duration_seconds' => round($this->durationSeconds, 4),
            'throughput_rps' => round($this->throughputRps, 2),
            'error_rate' => round($this->errorRate, 6),
            'latency_ms' => [
                'p50' => round($this->p50Ms, 2),
                'p95' => round($this->p95Ms, 2),
                'p99' => round($this->p99Ms, 2),
                'max' => round($this->maxMs, 2),
            ],
            'status_counts' => $this->statusCounts,
            'threshold' => $this->threshold->toArray(),
            'passed' => $this->passed(),
            'violations' => $this->violations,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @param list<float> $sortedSamples */
    private static function percentile(array $sortedSamples, float $percentile): float
    {
        $rank = max(1, (int) ceil(count($sortedSamples) * $percentile));

        return (float) $sortedSamples[$rank - 1];
    }
}
