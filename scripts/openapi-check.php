<?php

declare(strict_types=1);

$path = $argv[1] ?? dirname(__DIR__) . '/docs/openapi.yaml';
if (!is_file($path)) {
    fwrite(STDERR, "OpenAPI file not found: {$path}\n");
    exit(1);
}

$contents = (string) file_get_contents($path);
if (function_exists('yaml_parse_file')) {
    $parsed = yaml_parse_file($path);
    if (!is_array($parsed)) {
        fwrite(STDERR, "OpenAPI YAML could not be parsed.\n");
        exit(1);
    }

    if (($parsed['openapi'] ?? null) !== '3.1.0') {
        fwrite(STDERR, "OpenAPI contract must declare version 3.1.0.\n");
        exit(1);
    }

    exit(0);
}

$requiredFragments = [
    'openapi: 3.1.0',
    '/api/v1/health:',
    '/api/v1/cron/status:',
    'SuccessEnvelope:',
    'ErrorEnvelope:',
];

foreach ($requiredFragments as $fragment) {
    if (!str_contains($contents, $fragment)) {
        fwrite(STDERR, "OpenAPI contract is missing required fragment: {$fragment}\n");
        exit(1);
    }
}

fwrite(STDERR, "PHP yaml extension is unavailable; completed structural OpenAPI fallback check.\n");
