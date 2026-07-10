<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;

final class ApacheRewriteContractTest extends TestCase
{
    public function testPublicHtaccessRoutesInstallerAndApiRequestsThroughFrontController(): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        self::assertStringContainsString('Options -Indexes -MultiViews', $contents);
        self::assertStringContainsString('DirectoryIndex index.php', $contents);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $contents);
        self::assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-d', $contents);
        self::assertStringContainsString('RewriteRule ^ index.php [QSA,L]', $contents);
        self::assertStringContainsString('HTTP_AUTHORIZATION:%{HTTP:Authorization}', $contents);
        self::assertStringContainsString('FallbackResource /index.php', $contents);
    }
}
