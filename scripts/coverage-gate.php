<?php

declare(strict_types=1);

const REQUIRED_LINE_RATE = 1.0;

$root = dirname(__DIR__);
$phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'phpunit' . DIRECTORY_SEPARATOR
    . 'phpunit' . DIRECTORY_SEPARATOR . 'phpunit';
$coverageDir = $root . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'coverage';
$clover = $coverageDir . DIRECTORY_SEPARATOR . 'clover.xml';

if (!is_file($phpunit)) {
    fwrite(STDERR, "PHPUnit executable was not found. Run composer install first.\n");
    exit(1);
}

if (!is_dir($coverageDir) && !mkdir($coverageDir, 0777, true) && !is_dir($coverageDir)) {
    fwrite(STDERR, "Unable to create coverage output directory: {$coverageDir}\n");
    exit(1);
}

@unlink($clover);

$coverageRunner = coverageRunner($phpunit, $clover);
if ($coverageRunner !== null) {
    $coverage = runCommand($coverageRunner);
    echo $coverage['output'];

    if ($coverage['exitCode'] === 0 && is_file($clover)) {
        $lineRate = cloverLineRate($clover);
        $percent = $lineRate * 100;
        printf("Coverage line-rate: %.2f%%; required: 100.00%%\n", $percent);

        if ($lineRate >= REQUIRED_LINE_RATE) {
            exit(0);
        }

        fwrite(STDERR, sprintf("Coverage gate failed: %.2f%% line coverage is below 100.00%%.\n", $percent));
        exit(1);
    }

    if (!coverageDriverBlocked($coverage['output']) && $coverage['exitCode'] !== 0) {
        exit($coverage['exitCode']);
    }
}

fwrite(STDERR, coverageBlockerMessage());
$tests = runCommand([PHP_BINARY, $phpunit]);
echo $tests['output'];

exit($tests['exitCode']);

/**
 * @return list<string>|null
 */
function coverageRunner(string $phpunit, string $clover): ?array
{
    $args = [$phpunit, '--coverage-clover', $clover, '--coverage-text'];

    if (extension_loaded('xdebug') || extension_loaded('pcov') || PHP_SAPI === 'phpdbg') {
        return [PHP_BINARY, ...$args];
    }

    $phpdbg = findExecutable('phpdbg');
    if ($phpdbg !== null) {
        return [$phpdbg, '-qrr', ...$args];
    }

    return null;
}

function coverageBlockerMessage(): string
{
    return <<<TEXT
Coverage driver blocker: no working PHPUnit coverage driver is available.
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
