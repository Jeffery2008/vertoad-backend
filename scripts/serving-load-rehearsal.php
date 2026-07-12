<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use VertoAD\Tests\Load\ServingLoadOptions;
use VertoAD\Tests\Load\ServingLoadScenario;

if (in_array('--help', array_slice($argv, 1), true)) {
    fwrite(STDOUT, ServingLoadOptions::usage());
    exit(0);
}

$redact = static function (string $value): string {
    $secrets = [];
    foreach (['DB_PASSWORD', 'REDIS_PASSWORD', 'APP_KEY', 'CRON_API_TOKEN'] as $name) {
        $secret = getenv($name);
        if (is_string($secret) && $secret !== '') {
            $secrets[] = $secret;
        }
    }
    if ($secrets !== []) {
        $value = str_replace($secrets, '[redacted]', $value);
    }

    return substr((string) (preg_replace('/\s+/', ' ', trim($value)) ?? ''), 0, 1_000);
};

try {
    $options = ServingLoadOptions::fromArgv($argv);
    $report = (new ServingLoadScenario(dirname(__DIR__), $options))->run();
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(($report['passed'] ?? false) === true ? 0 : 1);
} catch (Throwable $exception) {
    $report = [
        'schema_version' => 1,
        'passed' => false,
        'fatal_error' => $redact($exception::class . ': ' . $exception->getMessage()),
        'violations' => ['The load runner could not start.'],
        'cleanup' => null,
    ];
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
