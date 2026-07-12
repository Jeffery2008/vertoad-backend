<?php

declare(strict_types=1);

use VertoAD\Bootstrap\EnvironmentLoader;

$root = __DIR__;

if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

EnvironmentLoader::load($root);

$databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: null;

$baseEnvironment = [
    'adapter' => 'mysql',
    'host' => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
    'name' => $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'vertoad',
    'user' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'vertoad',
    'pass' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
    'port' => (int) ($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

if ($databaseUrl !== null && $databaseUrl !== '') {
    $parts = parse_url($databaseUrl);

    if ($parts !== false) {
        $baseEnvironment = array_merge($baseEnvironment, [
            'host' => $parts['host'] ?? $baseEnvironment['host'],
            'name' => isset($parts['path']) ? ltrim($parts['path'], '/') : $baseEnvironment['name'],
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : $baseEnvironment['user'],
            'pass' => isset($parts['pass']) ? rawurldecode($parts['pass']) : $baseEnvironment['pass'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : $baseEnvironment['port'],
        ]);
    }
}

return [
    'paths' => [
        'migrations' => $root . '/db/migrations',
        'seeds' => $root . '/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $_ENV['PHINX_ENVIRONMENT'] ?? getenv('PHINX_ENVIRONMENT') ?: 'development',
        'development' => $baseEnvironment,
        'testing' => array_merge($baseEnvironment, [
            'name' => $_ENV['TEST_DB_DATABASE'] ?? getenv('TEST_DB_DATABASE') ?: $baseEnvironment['name'] . '_test',
        ]),
        'production' => $baseEnvironment,
    ],
    'version_order' => 'creation',
];
