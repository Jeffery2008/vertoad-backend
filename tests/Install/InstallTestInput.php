<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

final class InstallTestInput
{
    /** @return array<string, mixed> */
    public static function valid(bool $local = false): array
    {
        $appUrl = $local ? 'http://localhost:5173' : 'https://app.vertoad.example';
        $apiUrl = $local ? 'http://127.0.0.1:8080' : 'https://api.vertoad.example';

        return [
            'db_host' => 'db.internal.example',
            'db_port' => 3306,
            'db_name' => 'vertoad',
            'db_username' => 'vertoad_app',
            'db_password' => 'Db$Password-2026',
            'admin_email' => 'owner@example.com',
            'admin_password' => 'Correct-Horse-2026!',
            'admin_password_confirmation' => 'Correct-Horse-2026!',
            'admin_display_name' => 'Platform Owner',
            'organization_name' => 'VertoAD Admin',
            'organization_slug' => 'vertoad-admin',
            'app_url' => $appUrl,
            'api_url' => $apiUrl,
            'sdk_public_base_url' => $local ? 'http://localhost:4173/sdk' : 'https://sdk.vertoad.example',
            'ads_public_base_url' => $local ? 'http://127.0.0.1:8080/ads' : 'https://ads.vertoad.example',
            'oauth_redirect_uri' => $appUrl . '/oauth/installed-callback',
        ];
    }
}
