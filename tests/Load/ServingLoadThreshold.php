<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

final readonly class ServingLoadThreshold
{
    public function __construct(
        public float $maxP95Ms,
        public float $maxErrorRate,
        public float $minThroughputRps,
    ) {
        if ($this->maxP95Ms <= 0.0 || $this->minThroughputRps <= 0.0) {
            throw new \InvalidArgumentException('Latency and throughput thresholds must be positive.');
        }
        if ($this->maxErrorRate < 0.0 || $this->maxErrorRate > 1.0) {
            throw new \InvalidArgumentException('Error-rate threshold must be between 0 and 1.');
        }
    }

    /** @return array{p95_ms:float,max_error_rate:float,min_throughput_rps:float} */
    public function toArray(): array
    {
        return [
            'p95_ms' => $this->maxP95Ms,
            'max_error_rate' => $this->maxErrorRate,
            'min_throughput_rps' => $this->minThroughputRps,
        ];
    }
}
