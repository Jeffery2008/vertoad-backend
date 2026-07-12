<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;

final class InstallAppFactoryTest extends TestCase
{
    private string|false $previousEnvironment;
    private string|false $previousInstalled;
    private string|false $previousEnvironmentFile;
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        $this->previousEnvironment = getenv('APP_ENV');
        $this->previousInstalled = getenv('APP_INSTALLED');
        $this->previousEnvironmentFile = getenv('VERTOAD_ENV_FILE');
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('APP_ENV', $this->previousEnvironment);
        $this->restoreEnvironment('APP_INSTALLED', $this->previousInstalled);
        $this->restoreEnvironment('VERTOAD_ENV_FILE', $this->previousEnvironmentFile);
        foreach (array_reverse($this->temporaryDirectories) as $path) {
            $this->removeDirectory($path);
        }
    }

    public function testInstallerLoadsConfigurationFromAnExternalEnvironmentFile(): void
    {
        putenv('APP_ENV');
        putenv('APP_INSTALLED');
        $root = $this->temporaryAppRoot();
        $environmentDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-env-' . bin2hex(random_bytes(6));
        mkdir($environmentDirectory, 0700, true);
        $this->temporaryDirectories[] = $environmentDirectory;
        $environmentPath = $environmentDirectory . DIRECTORY_SEPARATOR . 'staging.env';
        file_put_contents($environmentPath, "APP_ENV=local\nAPP_INSTALLED=false\n");
        putenv('VERTOAD_ENV_FILE=' . $environmentPath);

        $response = AppFactory::create($root)->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/install',
            ['REMOTE_ADDR' => '127.0.0.1'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Secure installation', (string) $response->getBody());
        self::assertFileDoesNotExist($root . DIRECTORY_SEPARATOR . '.env');
    }

    public function testUninstalledApplicationExposesInstallerAndFailsOtherRoutesClosed(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_INSTALLED=false');
        $root = $this->temporaryAppRoot();
        $app = AppFactory::create($root);

        $installer = $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/install',
            ['REMOTE_ADDR' => '127.0.0.1'],
        ));
        self::assertSame(200, $installer->getStatusCode());
        self::assertStringContainsString('Secure installation', (string) $installer->getBody());
        self::assertNotSame('', $installer->getHeaderLine('X-Request-Id'));

        $api = $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://localhost/api/v1/health',
            ['REMOTE_ADDR' => '127.0.0.1'],
        )->withHeader('X-Request-Id', 'installation-required-request'));
        $payload = json_decode((string) $api->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(503, $api->getStatusCode());
        self::assertSame('installation_required', $payload['error']['code']);
        self::assertSame('installation-required-request', $payload['request_id']);
        self::assertSame('60', $api->getHeaderLine('Retry-After'));
    }

    public function testInstalledApplicationPermanentlyDisablesInstallerRoute(): void
    {
        putenv('APP_ENV=testing');
        putenv('APP_INSTALLED=true');
        $app = AppFactory::create(dirname(__DIR__, 2));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://api.example.test/install',
            ['REMOTE_ADDR' => '198.51.100.5'],
        ));

        self::assertSame(410, $response->getStatusCode());
        self::assertStringContainsString('Installer disabled', (string) $response->getBody());

        $put = $app->handle((new ServerRequestFactory())->createServerRequest(
            'PUT',
            'https://api.example.test/install',
            ['REMOTE_ADDR' => '198.51.100.5'],
        ));
        self::assertSame(410, $put->getStatusCode());
    }

    private function temporaryAppRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-app-' . bin2hex(random_bytes(6));
        mkdir($root . '/config', 0700, true);
        mkdir($root . '/db/migrations', 0700, true);
        mkdir($root . '/db/seeds', 0700, true);
        file_put_contents($root . '/config/settings.php', <<<'PHP'
<?php
declare(strict_types=1);
return [
    'app' => ['env' => 'local'],
    'install' => ['token' => ''],
    'cloudflare' => ['real_ip_header' => 'CF-Connecting-IP', 'trusted_proxies' => []],
];
PHP);
        $this->temporaryDirectories[] = $root;

        return $root;
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
