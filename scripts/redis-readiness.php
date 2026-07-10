<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Predis\Client;
use Predis\Response\ServerException;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisReadinessPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $envFile = dirname(__DIR__) . '/.env';
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            fwrite(STDOUT, "Usage: php scripts/redis-readiness.php [--env-file=PATH]\n");
            exit(0);
        }
        if (str_starts_with($argument, '--env-file=')) {
            $envFile = substr($argument, strlen('--env-file='));
            continue;
        }

        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    $envFile = trim($envFile);
    if ($envFile === '' || !is_file($envFile)) {
        throw new RuntimeException('Redis readiness env file was not found.');
    }

    $fileEnvironment = Dotenv::createArrayBacked(dirname($envFile), basename($envFile))->load();
    $environment = static fn (string $key, ?string $default = null): string =>
        (string) ($fileEnvironment[$key] ?? $default ?? '');

    $password = $environment('REDIS_PASSWORD');
    $settings = [
        'driver' => $environment('REDIS_DRIVER', 'auto'),
        'host' => $environment('REDIS_HOST', '127.0.0.1'),
        'port' => (int) $environment('REDIS_PORT', '6379'),
        'password' => $password,
        'database' => (int) $environment('REDIS_DATABASE', '0'),
        'prefix' => $environment('REDIS_PREFIX', 'vertoad:'),
        'timeout_seconds' => (float) $environment('REDIS_TIMEOUT_SECONDS', '5'),
        'read_timeout_seconds' => (float) $environment('REDIS_READ_TIMEOUT_SECONDS', '5'),
    ];

    $redis = RedisClientFactory::fromSettings($settings);
    $probeKey = $settings['prefix'] . 'readiness:' . bin2hex(random_bytes(12));
    try {
        if (!$redis->setEx($probeKey, 'ready', 30)
            || $redis->get($probeKey) !== 'ready'
            || !$redis->expire($probeKey, 15)) {
            throw new RuntimeException('Redis authenticated read/write/TTL probe failed.');
        }
    } finally {
        $redis->delete($probeKey);
    }

    $client = new Client(RedisClientFactory::predisParameters($settings));
    $client->auth($password);
    if ($settings['database'] > 0) {
        $client->select($settings['database']);
    }

    $serverInfo = (string) $client->executeRaw(['INFO', 'server']);
    $version = preg_match('/^redis_version:([^\r\n]+)/m', $serverInfo, $matches) === 1
        ? trim($matches[1])
        : '';
    $username = (string) $client->executeRaw(['ACL', 'WHOAMI']);
    $policy = new RedisReadinessPolicy();
    $commandAccess = [];
    foreach (RedisReadinessPolicy::DANGEROUS_COMMANDS as $command) {
        $arguments = ['ACL', 'DRYRUN', $username, $command];
        if ($command === 'CONFIG') {
            $arguments[] = 'GET';
            $arguments[] = '*';
        }

        try {
            $result = (string) $client->executeRaw($arguments);
            $commandAccess[$command] = $policy->interpretAclDryRun($command, $result, null);
        } catch (ServerException $exception) {
            $commandAccess[$command] = $policy->interpretAclDryRun($command, null, $exception->getMessage());
        }
    }
    $client->disconnect();

    $issues = $policy->assess($password, $version, $commandAccess);
    fwrite(STDOUT, "redis_authenticated_round_trip=passed\n");
    fwrite(STDOUT, 'redis_version=' . ($version === '' ? 'unverifiable' : $version) . "\n");
    if ($issues !== []) {
        foreach ($issues as $issue) {
            fwrite(STDERR, 'redis_readiness_issue=' . $issue . "\n");
        }
        exit(1);
    }

    fwrite(STDOUT, "redis_readiness=passed\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'redis_readiness_failed=' . $exception->getMessage() . "\n");
    exit(1);
}
