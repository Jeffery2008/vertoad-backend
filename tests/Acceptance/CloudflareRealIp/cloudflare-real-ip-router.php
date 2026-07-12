<?php

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/__cloudflare-real-ip/transport') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'cf_connecting_ip' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        'cf_ray' => (string) ($_SERVER['HTTP_CF_RAY'] ?? ''),
        'cf_ipcountry' => (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''),
        'cf_visitor' => (string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''),
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
        'forwarded_for' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return;
}

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
