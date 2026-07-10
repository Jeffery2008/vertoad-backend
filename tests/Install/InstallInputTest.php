<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VertoAD\Install\InstallInput;

final class InstallInputTest extends TestCase
{
    public function testCreatesNormalizedRemoteInputAndDatabaseSettings(): void
    {
        $payload = InstallTestInput::valid();
        $payload['admin_email'] = ' OWNER@Example.COM ';
        $payload['organization_slug'] = ' VERTOAD-ADMIN ';
        $payload['oauth_redirect_uri'] = 'https://app.vertoad.example/oauth/installed-callback?source=installer';
        $input = InstallInput::fromArray($payload, false);

        self::assertSame('owner@example.com', $input->adminEmail);
        self::assertSame('vertoad-admin', $input->organizationSlug);
        self::assertSame('https://app.vertoad.example/oauth/installed-callback?source=installer', $input->oauthRedirectUri);
        self::assertSame([
            'driver' => 'pdo_mysql',
            'host' => 'db.internal.example',
            'port' => 3306,
            'database' => 'vertoad',
            'username' => 'vertoad_app',
            'password' => 'Db$Password-2026',
            'charset' => 'utf8mb4',
        ], $input->databaseSettings());
    }

    public function testLocalInputAllowsLoopbackHttpAndDefaultsOauthCallback(): void
    {
        $payload = InstallTestInput::valid(local: true);
        unset($payload['oauth_redirect_uri']);
        $input = InstallInput::fromArray($payload, true);

        self::assertSame('http://localhost:5173/oauth/callback', $input->oauthRedirectUri);
        self::assertSame('http://127.0.0.1:8080', $input->apiUrl);
    }

    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInput(string $field, mixed $value, string $message, bool $allowLocalHttp = false): void
    {
        $payload = InstallTestInput::valid($allowLocalHttp);
        $payload[$field] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        InstallInput::fromArray($payload, $allowLocalHttp);
    }

    /** @return iterable<string, array{string, mixed, string, bool?}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'required field' => ['db_host', '  ', 'Db host is required.'];
        yield 'field too long' => ['admin_display_name', str_repeat('a', 161), 'Admin display name is too long.'];
        yield 'database host syntax' => ['db_host', 'mysql://db', 'Database host is invalid.'];
        yield 'database port' => ['db_port', 70000, 'Database port must be between 1 and 65535.'];
        yield 'database name' => ['db_name', 'vertoad.prod', 'Database name contains unsupported characters.'];
        yield 'database username control' => ['db_username', "root\nadmin", 'Database username contains control characters.'];
        yield 'database password control' => ['db_password', "secret\rvalue", 'Database password contains unsupported control characters.'];
        yield 'administrator email' => ['admin_email', 'not-an-email', 'Administrator email is invalid.'];
        yield 'short administrator password' => ['admin_password', 'Abc123!', 'Administrator password must be at least 14 characters'];
        yield 'password contains email name' => ['admin_password', 'Owner-Correct-2026!', 'Administrator password must be at least 14 characters'];
        yield 'password confirmation mismatch' => ['admin_password_confirmation', 'Different-Horse-2026!', 'Administrator password confirmation does not match.'];
        yield 'organization slug' => ['organization_slug', 'Bad--Slug', 'Organization slug must contain lowercase letters'];
        yield 'invalid URL' => ['app_url', 'not a url', 'App url must be a valid URL.'];
        yield 'remote URL requires HTTPS' => ['api_url', 'http://api.example.test', 'Api url must use HTTPS.'];
        yield 'local HTTP only allows loopback' => ['api_url', 'http://api.example.test', 'Api url must use HTTPS.', true];
        yield 'URL credentials' => ['api_url', 'https://user:pass@api.example.test', 'Api url must not contain credentials or fragments.'];
        yield 'URL fragment' => ['api_url', 'https://api.example.test/#fragment', 'Api url must not contain credentials or fragments.'];
        yield 'base URL query' => ['ads_public_base_url', 'https://ads.example.test/base?tenant=one', 'Ads public base url must not contain a query string.'];
        yield 'SDK script URL' => ['sdk_public_base_url', 'https://sdk.example.test/vertoad-sdk.js', 'Sdk public base url must not include a script filename.'];
    }
}
