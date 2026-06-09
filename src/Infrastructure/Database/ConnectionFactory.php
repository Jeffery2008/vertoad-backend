<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class ConnectionFactory
{
    /**
     * @param array<string, mixed> $settings
     */
    public function create(array $settings): Connection
    {
        if (($settings['driver'] ?? '') === 'pdo_sqlite') {
            return DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'memory' => (bool) ($settings['memory'] ?? false),
                'path' => $settings['path'] ?? null,
            ]);
        }

        return DriverManager::getConnection([
            'driver' => $settings['driver'] ?? 'pdo_mysql',
            'host' => $settings['host'] ?? '127.0.0.1',
            'port' => $settings['port'] ?? 3306,
            'dbname' => $settings['database'] ?? 'vertoad',
            'user' => $settings['username'] ?? 'vertoad',
            'password' => $settings['password'] ?? '',
            'charset' => $settings['charset'] ?? 'utf8mb4',
        ]);
    }
}
