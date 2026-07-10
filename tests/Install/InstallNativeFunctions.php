<?php

declare(strict_types=1);

namespace VertoAD\Install;

function fopen(string $filename, string $mode): mixed
{
    $failure = $GLOBALS['vertoad_install_fopen_failure'] ?? null;
    if ($failure instanceof \Closure && $failure($filename, $mode)) {
        return false;
    }

    return \fopen($filename, $mode);
}

function is_file(string $filename): bool
{
    $failure = $GLOBALS['vertoad_install_is_file_failure'] ?? null;
    if ($failure instanceof \Closure && $failure($filename)) {
        return false;
    }

    return \is_file($filename);
}

function openssl_pkey_new(?array $options = null): \OpenSSLAsymmetricKey|false
{
    if (($GLOBALS['vertoad_install_openssl_supply_config'] ?? false) === true && !isset($options['config'])) {
        $locations = \openssl_get_cert_locations();
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            ($locations['default_default_cert_area'] ?? '') . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && \is_file($candidate)) {
                $options ??= [];
                $options['config'] = $candidate;
                break;
            }
        }
    }

    return \openssl_pkey_new($options);
}

function openssl_pkey_export(mixed $key, string &$output, ?string $passphrase = null, ?array $options = null): bool
{
    if (($GLOBALS['vertoad_install_openssl_export_failure'] ?? false) === true) {
        return false;
    }

    if (($GLOBALS['vertoad_install_openssl_supply_config'] ?? false) === true && !isset($options['config'])) {
        $locations = \openssl_get_cert_locations();
        foreach ([
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            ($locations['default_default_cert_area'] ?? '') . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && \is_file($candidate)) {
                $options ??= [];
                $options['config'] = $candidate;
                break;
            }
        }
    }

    return \openssl_pkey_export($key, $output, $passphrase, $options);
}

function openssl_pkey_get_details(mixed $key): array|false
{
    if (($GLOBALS['vertoad_install_openssl_details_failure'] ?? false) === true) {
        return false;
    }

    return \openssl_pkey_get_details($key);
}
