<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

final readonly class ServingLoadOptions
{
    /** @var array<string, int|float|string> */
    private const DEFAULTS = [
        'requests' => 160,
        'concurrency' => 16,
        'workers' => 4,
        'warmup' => 16,
        'timeout-ms' => 12_000,
        'max-error-rate' => 0.0,
        'serve-p95-ms' => 1_500.0,
        'serve-min-rps' => 10.0,
        'track-p95-ms' => 1_500.0,
        'track-min-rps' => 10.0,
        'click-p95-ms' => 1_500.0,
        'click-min-rps' => 10.0,
    ];

    /** @var array<string, string> */
    private const ENVIRONMENT_NAMES = [
        'requests' => 'VERTOAD_SERVING_LOAD_REQUESTS',
        'concurrency' => 'VERTOAD_SERVING_LOAD_CONCURRENCY',
        'workers' => 'VERTOAD_SERVING_LOAD_WORKERS',
        'warmup' => 'VERTOAD_SERVING_LOAD_WARMUP',
        'timeout-ms' => 'VERTOAD_SERVING_LOAD_TIMEOUT_MS',
        'max-error-rate' => 'VERTOAD_SERVING_LOAD_MAX_ERROR_RATE',
        'serve-p95-ms' => 'VERTOAD_SERVING_LOAD_SERVE_P95_MS',
        'serve-min-rps' => 'VERTOAD_SERVING_LOAD_SERVE_MIN_RPS',
        'track-p95-ms' => 'VERTOAD_SERVING_LOAD_TRACK_P95_MS',
        'track-min-rps' => 'VERTOAD_SERVING_LOAD_TRACK_MIN_RPS',
        'click-p95-ms' => 'VERTOAD_SERVING_LOAD_CLICK_P95_MS',
        'click-min-rps' => 'VERTOAD_SERVING_LOAD_CLICK_MIN_RPS',
    ];

    public function __construct(
        public int $requests,
        public int $concurrency,
        public int $workers,
        public int $warmup,
        public int $timeoutMs,
        public float $maxErrorRate,
        public float $serveP95Ms,
        public float $serveMinRps,
        public float $trackP95Ms,
        public float $trackMinRps,
        public float $clickP95Ms,
        public float $clickMinRps,
    ) {
        if ($this->requests < 1) {
            throw new \InvalidArgumentException('requests must be positive.');
        }
        if ($this->concurrency < 1 || $this->concurrency > $this->requests) {
            throw new \InvalidArgumentException('concurrency must be between 1 and requests.');
        }
        if ($this->workers < 1 || $this->workers > $this->concurrency) {
            throw new \InvalidArgumentException('workers must be between 1 and concurrency.');
        }
        if ($this->warmup < 0) {
            throw new \InvalidArgumentException('warmup must be non-negative.');
        }
        if ($this->timeoutMs < 100) {
            throw new \InvalidArgumentException('timeout-ms must be at least 100.');
        }
        if ($this->maxErrorRate < 0.0 || $this->maxErrorRate > 1.0) {
            throw new \InvalidArgumentException('max-error-rate must be between 0 and 1.');
        }
        foreach ([
            'serve-p95-ms' => $this->serveP95Ms,
            'serve-min-rps' => $this->serveMinRps,
            'track-p95-ms' => $this->trackP95Ms,
            'track-min-rps' => $this->trackMinRps,
            'click-p95-ms' => $this->clickP95Ms,
            'click-min-rps' => $this->clickMinRps,
        ] as $name => $value) {
            if ($value <= 0.0) {
                throw new \InvalidArgumentException($name . ' must be positive.');
            }
        }
    }

    /**
     * @param list<string> $argv
     * @param array<string, string>|null $environment
     */
    public static function fromArgv(array $argv, ?array $environment = null): self
    {
        $arguments = self::arguments($argv);
        $environment ??= self::processEnvironment();
        $values = [];

        foreach (self::DEFAULTS as $name => $default) {
            $environmentName = self::ENVIRONMENT_NAMES[$name];
            $environmentValue = trim((string) ($environment[$environmentName] ?? ''));
            $values[$name] = $arguments[$name] ?? ($environmentValue === '' ? $default : $environmentValue);
        }

        return new self(
            requests: self::integer($values['requests'], 'requests'),
            concurrency: self::integer($values['concurrency'], 'concurrency'),
            workers: self::integer($values['workers'], 'workers'),
            warmup: self::integer($values['warmup'], 'warmup'),
            timeoutMs: self::integer($values['timeout-ms'], 'timeout-ms'),
            maxErrorRate: self::decimal($values['max-error-rate'], 'max-error-rate'),
            serveP95Ms: self::decimal($values['serve-p95-ms'], 'serve-p95-ms'),
            serveMinRps: self::decimal($values['serve-min-rps'], 'serve-min-rps'),
            trackP95Ms: self::decimal($values['track-p95-ms'], 'track-p95-ms'),
            trackMinRps: self::decimal($values['track-min-rps'], 'track-min-rps'),
            clickP95Ms: self::decimal($values['click-p95-ms'], 'click-p95-ms'),
            clickMinRps: self::decimal($values['click-min-rps'], 'click-min-rps'),
        );
    }

    /** @return array<string, ServingLoadThreshold> */
    public function thresholds(): array
    {
        return [
            'serve' => new ServingLoadThreshold($this->serveP95Ms, $this->maxErrorRate, $this->serveMinRps),
            'track' => new ServingLoadThreshold($this->trackP95Ms, $this->maxErrorRate, $this->trackMinRps),
            'click' => new ServingLoadThreshold($this->clickP95Ms, $this->maxErrorRate, $this->clickMinRps),
        ];
    }

    /** @return array<string, int|float|string> */
    public function toArray(): array
    {
        return [
            'requests_per_stage' => $this->requests,
            'concurrency' => $this->concurrency,
            'http_workers' => $this->workers,
            'warmup_flows' => $this->warmup,
            'request_timeout_ms' => $this->timeoutMs,
            'threshold_basis' => self::thresholdBasis(),
        ];
    }

    public static function thresholdBasis(): string
    {
        return 'Single-node hot-path release floor for the documented 1 vCPU test target with four PHP workers and canonical geo already resolved: 160 requests per endpoint at concurrency 16, zero functional errors, at least 10 successful requests/second, and p95 no higher than 1500 ms. This is a regression gate, not the production SLA.';
    }

    public static function usage(): string
    {
        return <<<'TEXT'
Usage: php scripts/serving-load-rehearsal.php [options]

Options use --name=value syntax:
  --requests=160             Requests measured for each endpoint.
  --concurrency=16           Maximum in-flight requests.
  --workers=4                Isolated loopback PHP HTTP workers sized for 1 vCPU.
  --warmup=16                Unmeasured complete serve/track/click flows.
  --timeout-ms=12000         Per-request timeout.
  --max-error-rate=0         Allowed transport or contract error fraction.
  --serve-p95-ms=1500        Serve p95 ceiling.
  --serve-min-rps=10         Serve successful throughput floor.
  --track-p95-ms=1500        Track p95 ceiling.
  --track-min-rps=10         Track successful throughput floor.
  --click-p95-ms=1500        Click p95 ceiling.
  --click-min-rps=10         Click successful throughput floor.
  --help                     Show this help without connecting to infrastructure.
TEXT;
    }

    /** @param list<string> $argv
     * @return array<string, string>
     */
    private static function arguments(array $argv): array
    {
        $arguments = [];
        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--help') {
                continue;
            }
            if (preg_match('/^--([a-z0-9-]+)=(.+)$/D', $argument, $matches) !== 1) {
                throw new \InvalidArgumentException('Invalid argument: ' . $argument);
            }
            $name = $matches[1];
            if (!array_key_exists($name, self::DEFAULTS)) {
                throw new \InvalidArgumentException('Unknown argument: --' . $name);
            }
            if (array_key_exists($name, $arguments)) {
                throw new \InvalidArgumentException('Duplicate argument: --' . $name);
            }
            $arguments[$name] = $matches[2];
        }

        return $arguments;
    }

    /** @return array<string, string> */
    private static function processEnvironment(): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            return [];
        }

        return array_map(static fn (mixed $value): string => (string) $value, $environment);
    }

    private static function integer(int|float|string $value, string $name): int
    {
        $string = (string) $value;
        if (filter_var($string, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException($name . ' must be an integer.');
        }

        return (int) $string;
    }

    private static function decimal(int|float|string $value, string $name): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($name . ' must be numeric.');
        }

        return (float) $value;
    }
}
