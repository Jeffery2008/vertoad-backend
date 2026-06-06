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
        return DriverManager::getConnection([
            'driver' => $settings['driver'],
            'host' => $settings['host'],
            'port' => $settings['port'],
            'dbname' => $settings['database'],
            'user' => $settings['username'],
            'password' => $settings['password'],
            'charset' => $settings['charset'],
        ]);
    }
}
