<?php

declare(strict_types=1);

const REQUIRED_LINE_RATE = 1.0;

if (!defined('VERTOAD_COVERAGE_GATE_TESTING')) {
    exit(coverageGateMain());
}

function coverageGateMain(): int
{
    $root = dirname(__DIR__);
    $phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR
        . 'phpunit' . DIRECTORY_SEPARATOR . 'phpunit';
    $coverageDir = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'coverage';
    $clover = $coverageDir . DIRECTORY_SEPARATOR . 'clover.xml';

    if (!is_file($phpunit)) {
        fwrite(STDERR, "PHPUnit executable was not found. Run composer install first.\n");
        return 1;
    }

    if (!is_dir($coverageDir) && !mkdir($coverageDir, 0777, true) && !is_dir($coverageDir)) {
        fwrite(STDERR, "Unable to create coverage output directory: {$coverageDir}\n");
        return 1;
    }

    @unlink($clover);

    $diagnostics = currentCoverageDriverDiagnostics();
    $coverageRunner = coverageRunner($phpunit, $clover, $diagnostics);
    if ($coverageRunner !== null) {
        $coverage = runCommand($coverageRunner);
        echo $coverage['output'];

        if ($coverage['exitCode'] === 0 && is_file($clover)) {
            $lineRate = cloverLineRate($clover);
            $percent = $lineRate * 100;
            printf("Coverage line-rate: %.2f%%; required: 100.00%%\n", $percent);

            if ($lineRate >= REQUIRED_LINE_RATE) {
                return 0;
            }

            fwrite(STDERR, sprintf("Coverage gate failed: %.2f%% line coverage is below 100.00%%.\n", $percent));
            return 1;
        }

        if (!coverageDriverBlocked($coverage['output']) && $coverage['exitCode'] !== 0) {
            return $coverage['exitCode'];
        }
    }

    fwrite(STDERR, coverageFallbackBlockerMessage($diagnostics));
    $tests = runCommand([PHP_BINARY, $phpunit, ...phpunitGateArguments()]);
    echo $tests['output'];

    return $tests['exitCode'];
}

/**
 * @return list<string>|null
 */
function coverageRunner(string $phpunit, string $clover, ?array $diagnostics = null): ?array
{
    $args = [$phpunit, ...phpunitGateArguments(), '--coverage-clover', $clover, '--coverage-text'];
    $diagnostics ??= currentCoverageDriverDiagnostics();

    if (!$diagnostics['available']) {
        return null;
    }

    return [...$diagnostics['runnerPrefix'], ...$args];
}

/**
 * @return list<string>
 */
function phpunitGateArguments(): array
{
    return ['--exclude-group', 'redis-integration', '--fail-on-skipped'];
}

/**
 * @return array{available:bool, runnerPrefix:list<string>|null, message:string, sapi:string, coverageExtensions:list<string>, phpdbgPath:string|null}
 */
function currentCoverageDriverDiagnostics(): array
{
    return coverageDriverDiagnostics(
        loadedExtensions: get_loaded_extensions(),
        sapi: PHP_SAPI,
        phpdbgPath: findExecutable('phpdbg'),
    );
}

/**
 * @param list<string> $loadedExtensions
 * @return array{available:bool, runnerPrefix:list<string>|null, message:string, sapi:string, coverageExtensions:list<string>, phpdbgPath:string|null}
 */
function coverageDriverDiagnostics(array $loadedExtensions, string $sapi, ?string $phpdbgPath): array
{
    $extensions = array_map('strtolower', $loadedExtensions);
    $coverageExtensions = array_values(array_intersect($extensions, ['xdebug', 'pcov']));

    if ($coverageExtensions !== [] || $sapi === 'phpdbg') {
        return [
            'available' => true,
            'runnerPrefix' => [PHP_BINARY],
            'message' => '',
            'sapi' => $sapi,
            'coverageExtensions' => $coverageExtensions,
            'phpdbgPath' => $phpdbgPath,
        ];
    }

    if ($phpdbgPath !== null) {
        return [
            'available' => true,
            'runnerPrefix' => [$phpdbgPath, '-qrr'],
            'message' => '',
            'sapi' => $sapi,
            'coverageExtensions' => $coverageExtensions,
            'phpdbgPath' => $phpdbgPath,
        ];
    }

    $message = coverageBlockerMessage($sapi, $coverageExtensions, $phpdbgPath);

    return [
        'available' => false,
        'runnerPrefix' => null,
        'message' => $message,
        'sapi' => $sapi,
        'coverageExtensions' => $coverageExtensions,
        'phpdbgPath' => $phpdbgPath,
    ];
}

/**
 * @param array{sapi:string, coverageExtensions:list<string>, phpdbgPath:string|null} $diagnostics
 */
function coverageFallbackBlockerMessage(array $diagnostics): string
{
    return coverageBlockerMessage(
        $diagnostics['sapi'],
        $diagnostics['coverageExtensions'],
        $diagnostics['phpdbgPath'],
    );
}

/**
 * @param list<string> $coverageExtensions
 */
function coverageBlockerMessage(string $sapi, array $coverageExtensions, ?string $phpdbgPath): string
{
    $extensionSummary = $coverageExtensions === [] ? 'none' : implode(', ', $coverageExtensions);
    $phpdbgSummary = $phpdbgPath ?? 'not found';

    return <<<TEXT
Coverage driver blocker: no working PHPUnit coverage driver is available.
Detected SAPI: {$sapi}
Detected coverage extensions: {$extensionSummary}
Detected phpdbg: {$phpdbgSummary}
Install/enable Xdebug with XDEBUG_MODE=coverage, PCOV, or a phpdbg build that exposes code coverage.
Running PHPUnit without coverage so the test suite still gates this environment.

TEXT;
}

/**
 * @param list<string> $command
 * @return array{exitCode:int, output:string}
 */
function runCommand(array $command): array
{
    $descriptorSpec = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(array_map('strval', $command), $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ['exitCode' => 1, 'output' => 'Unable to start command: ' . implode(' ', $command) . PHP_EOL];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'output' => $stdout . $stderr,
    ];
}

function findExecutable(string $name): ?string
{
    $command = PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['sh', '-c', 'command -v ' . escapeshellarg($name)];
    $result = runCommand($command);
    if ($result['exitCode'] !== 0) {
        return null;
    }

    $lines = preg_split('/\R/', trim($result['output']));
    $first = $lines[0] ?? '';

    return $first === '' ? null : $first;
}

function coverageDriverBlocked(string $output): bool
{
    return str_contains($output, 'No code coverage driver available')
        || str_contains($output, 'XDEBUG_MODE=coverage')
        || str_contains($output, 'Code coverage needs to be enabled');
}

function cloverLineRate(string $clover): float
{
    $xml = simplexml_load_file($clover);
    if ($xml === false) {
        fwrite(STDERR, "Unable to parse Clover coverage report: {$clover}\n");
        exit(1);
    }

    $metrics = $xml->project->metrics;
    $statements = (int) $metrics['statements'];
    $coveredStatements = (int) $metrics['coveredstatements'];

    return $statements === 0 ? 1.0 : $coveredStatements / $statements;
}
