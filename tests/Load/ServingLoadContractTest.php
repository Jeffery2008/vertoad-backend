<?php

declare(strict_types=1);

namespace VertoAD\Tests\Load;

use PHPUnit\Framework\TestCase;

final class ServingLoadContractTest extends TestCase
{
    public function testDefaultsDescribeTheDocumentedSingleNodeReleaseFloor(): void
    {
        $options = ServingLoadOptions::fromArgv(['runner'], []);

        self::assertSame(160, $options->requests);
        self::assertSame(16, $options->concurrency);
        self::assertSame(4, $options->workers);
        self::assertSame(16, $options->warmup);
        self::assertSame(1_500.0, $options->serveP95Ms);
        self::assertSame(10.0, $options->clickMinRps);
        self::assertStringContainsString('1 vCPU', ServingLoadOptions::thresholdBasis());
    }

    public function testEnvironmentValuesAreOverriddenByExplicitArguments(): void
    {
        $options = ServingLoadOptions::fromArgv(
            ['runner', '--requests=8', '--workers=2'],
            [
                'VERTOAD_SERVING_LOAD_REQUESTS' => '12',
                'VERTOAD_SERVING_LOAD_CONCURRENCY' => '4',
                'VERTOAD_SERVING_LOAD_WORKERS' => '3',
                'VERTOAD_SERVING_LOAD_WARMUP' => '0',
                'VERTOAD_SERVING_LOAD_TIMEOUT_MS' => '900',
            ],
        );

        self::assertSame(8, $options->requests);
        self::assertSame(4, $options->concurrency);
        self::assertSame(2, $options->workers);
        self::assertSame(0, $options->warmup);
        self::assertSame(900, $options->timeoutMs);
    }

    public function testInvalidArgumentsAreRejectedBeforeInfrastructureAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown argument');

        ServingLoadOptions::fromArgv(['runner', '--not-a-real-option=1'], []);
    }

    public function testMetricsUseNearestRankPercentilesAndReportThresholdViolations(): void
    {
        $samples = array_map(static fn (int $value): float => (float) $value, range(1, 20));
        $metrics = ServingLoadStageMetrics::fromSamples(
            stage: 'serve',
            requests: 20,
            successes: 19,
            durationSeconds: 2.0,
            latenciesMs: $samples,
            statusCounts: ['200' => 19, '500' => 1],
            diagnostics: [['request' => 3, 'label' => 'serve-3', 'status' => 500, 'error' => 'failed', 'body' => '']],
            threshold: new ServingLoadThreshold(18.0, 0.0, 10.0),
        );

        self::assertSame(19.0, $metrics->p95Ms);
        self::assertSame(20.0, $metrics->p99Ms);
        self::assertSame(9.5, $metrics->throughputRps);
        self::assertSame(0.05, $metrics->errorRate);
        self::assertFalse($metrics->passed());
        self::assertCount(3, $metrics->violations);
    }

    public function testHelpPathDoesNotRequireDatabaseOrRedis(): void
    {
        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'serving-load-rehearsal.php';
        $process = proc_open(
            [PHP_BINARY, $script, '--help'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('serving-load-rehearsal.php', (string) $stdout);
        self::assertStringContainsString('--click-p95-ms', (string) $stdout);
        self::assertSame('', (string) $stderr);
    }
}
