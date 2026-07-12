<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'MysqlInstallRehearsalSupport.php';

final class MysqlInstallRehearsalContractTest extends TestCase
{
    public function testExecutableRequiresExplicitOptInAndEmitsFailureEvidence(): void
    {
        $result = $this->runRehearsal([], [
            'VERTOAD_MYSQL_INSTALL_REHEARSAL',
            'MYSQL_REHEARSAL_HOST',
            'MYSQL_REHEARSAL_PORT',
            'MYSQL_REHEARSAL_USERNAME',
            'MYSQL_REHEARSAL_PASSWORD',
            'MYSQL_REHEARSAL_SSL_CA',
        ]);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(64, $result['exit_code'], $this->diagnostic($result));
        self::assertFalse($result['timed_out']);
        self::assertSame('vertoad.mysql-install-rehearsal.v1', $evidence['schema']);
        self::assertSame('failed', $evidence['status']);
        self::assertSame(64, $evidence['exit_code']);
        self::assertSame('configuration', $evidence['failure']['stage']);
        self::assertStringContainsString('VERTOAD_MYSQL_INSTALL_REHEARSAL=1', $evidence['failure']['message']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
    }

    public function testExecutableNeverSelectsRootImplicitlyOrPrintsThePassword(): void
    {
        $password = 'implicit-root-password-sentinel';
        $result = $this->runRehearsal([
            'VERTOAD_MYSQL_INSTALL_REHEARSAL' => '1',
            'MYSQL_REHEARSAL_HOST' => '127.0.0.1',
            'MYSQL_REHEARSAL_PASSWORD' => $password,
        ], [
            'MYSQL_REHEARSAL_USERNAME',
            'MYSQL_REHEARSAL_SSL_CA',
        ]);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(64, $result['exit_code'], $this->diagnostic($result, $password));
        self::assertSame('failed', $evidence['status']);
        self::assertStringContainsString('never selects root implicitly', $evidence['failure']['message']);
        self::assertStringNotContainsString($password, $result['stdout']);
        self::assertStringNotContainsString($password, $result['stderr']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
    }

    public function testExecutableRejectsNonLoopbackMysqlWithoutVerifiedTlsBeforeConnecting(): void
    {
        $password = 'remote-tls-password-sentinel';
        $result = $this->runRehearsal([
            'VERTOAD_MYSQL_INSTALL_REHEARSAL' => '1',
            'MYSQL_REHEARSAL_HOST' => '192.0.2.10',
            'MYSQL_REHEARSAL_PORT' => '3306',
            'MYSQL_REHEARSAL_USERNAME' => 'vertoad_rehearsal',
            'MYSQL_REHEARSAL_PASSWORD' => $password,
        ], ['MYSQL_REHEARSAL_SSL_CA']);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(64, $result['exit_code'], $this->diagnostic($result, $password));
        self::assertSame('failed', $evidence['status']);
        self::assertSame('configuration', $evidence['failure']['stage']);
        self::assertSame('remote', $evidence['mysql']['host_scope']);
        self::assertTrue($evidence['mysql']['tls_required']);
        self::assertFalse($evidence['mysql']['tls_ca_configured']);
        self::assertStringContainsString('MYSQL_REHEARSAL_SSL_CA is required', $evidence['failure']['message']);
        self::assertStringContainsString('server certificate verification is mandatory', $evidence['failure']['message']);
        self::assertStringNotContainsString($password, $result['stdout']);
        self::assertStringNotContainsString($password, $result['stderr']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
    }

    public function testExecutableRejectsRemoteRootBeforeConnectingOrPrintingThePassword(): void
    {
        $password = 'remote-root-password-sentinel';
        $result = $this->runRehearsal([
            'VERTOAD_MYSQL_INSTALL_REHEARSAL' => '1',
            'MYSQL_REHEARSAL_HOST' => '192.0.2.10',
            'MYSQL_REHEARSAL_PORT' => '3306',
            'MYSQL_REHEARSAL_USERNAME' => 'root',
            'MYSQL_REHEARSAL_PASSWORD' => $password,
        ], ['MYSQL_REHEARSAL_SSL_CA']);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(64, $result['exit_code'], $this->diagnostic($result, $password));
        self::assertSame('failed', $evidence['status']);
        self::assertSame('configuration', $evidence['failure']['stage']);
        self::assertStringContainsString('explicit non-root account', $evidence['failure']['message']);
        self::assertStringNotContainsString($password, $result['stdout']);
        self::assertStringNotContainsString($password, $result['stderr']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
    }

    public function testExecutableRejectsInstallerIncompatibleCredentialsBeforeConnecting(): void
    {
        $password = "invalid\npassword-sentinel";
        $result = $this->runRehearsal([
            'VERTOAD_MYSQL_INSTALL_REHEARSAL' => '1',
            'MYSQL_REHEARSAL_HOST' => '127.0.0.1',
            'MYSQL_REHEARSAL_PORT' => '3306',
            'MYSQL_REHEARSAL_USERNAME' => 'vertoad_rehearsal',
            'MYSQL_REHEARSAL_PASSWORD' => $password,
        ]);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(64, $result['exit_code'], $this->diagnostic($result, $password));
        self::assertSame('failed', $evidence['status']);
        self::assertSame('configuration', $evidence['failure']['stage']);
        self::assertStringContainsString('unsupported characters', $evidence['failure']['message']);
        self::assertStringNotContainsString('password-sentinel', $result['stdout']);
        self::assertStringNotContainsString('password-sentinel', $result['stderr']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
    }

    public function testRemoteMysqlDriverOptionsEnableCaAndServerCertificateVerification(): void
    {
        $options = \mysqlInstallRehearsalDriverOptions(__FILE__, 7);
        $phinx = \mysqlInstallRehearsalPhinxEnvironment([
            'driver' => 'pdo_mysql',
            'host' => 'mysql.internal.example',
            'port' => 3306,
            'database' => 'vertoad',
            'username' => 'vertoad',
            'password' => 'database-password-sentinel',
            'charset' => 'utf8mb4',
        ], __FILE__);

        self::assertSame(7, $options[\PDO::ATTR_TIMEOUT]);
        self::assertSame(
            __FILE__,
            $options[\mysqlInstallRehearsalPdoMysqlAttribute('ATTR_SSL_CA')],
        );
        self::assertTrue(
            $options[\mysqlInstallRehearsalPdoMysqlAttribute('ATTR_SSL_VERIFY_SERVER_CERT')],
        );
        self::assertSame(__FILE__, $phinx['mysql_attr_ssl_ca']);
        self::assertTrue($phinx['mysql_attr_ssl_verify_server_cert']);
        self::assertTrue(\mysqlInstallRehearsalIsLoopback('localhost'));
        self::assertTrue(\mysqlInstallRehearsalIsLoopback('127.0.0.42'));
        self::assertTrue(\mysqlInstallRehearsalIsLoopback('::1'));
        self::assertTrue(\mysqlInstallRehearsalIsLoopback('0:0:0:0:0:0:0:1'));
        self::assertFalse(\mysqlInstallRehearsalIsLoopback('192.0.2.10'));
        self::assertFalse(\mysqlInstallRehearsalIsLoopback('mysql.internal.example'));
    }

    public function testCleanSubprocessEnvironmentDropsCallerApplicationDatabaseAndSecretVariables(): void
    {
        $autoloadPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $supportPath = __DIR__ . DIRECTORY_SEPARATOR . 'MysqlInstallRehearsalSupport.php';
        $parentPath = getenv('PATH');
        $program = sprintf(
            'require %s; require %s; mysqlInstallRehearsalSanitizeCurrentEnvironment(); echo json_encode(['
            . '"app_env" => getenv("APP_ENV"),'
            . '"db_password" => getenv("DB_PASSWORD"),'
            . '"mysql_password" => getenv("MYSQL_REHEARSAL_PASSWORD"),'
            . '"unrelated_secret" => getenv("VERTOAD_UNRELATED_SECRET"),'
            . '"path" => getenv("PATH")'
            . '], JSON_THROW_ON_ERROR);',
            var_export($autoloadPath, true),
            var_export($supportPath, true),
        );
        $result = \mysqlInstallRehearsalRunProcess(
            [PHP_BINARY, '-r', $program],
            dirname(__DIR__, 2),
            \mysqlInstallRehearsalCleanProcessEnvironment([
                'APP_ENV' => 'polluted',
                'DB_PASSWORD' => 'database-secret-sentinel',
                'MYSQL_REHEARSAL_PASSWORD' => 'mysql-secret-sentinel',
                'VERTOAD_UNRELATED_SECRET' => 'unrelated-secret-sentinel',
            ]),
            10,
        );
        self::assertSame(0, $result['exit_code'], $this->diagnostic($result));
        self::assertFalse($result['timed_out']);
        self::assertSame('', $result['stderr'], $this->diagnostic($result));
        $evidence = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(false, $evidence['app_env']);
        self::assertSame(false, $evidence['db_password']);
        self::assertSame(false, $evidence['mysql_password']);
        self::assertSame(false, $evidence['unrelated_secret']);
        self::assertSame($parentPath === false ? false : $parentPath, $evidence['path']);
        self::assertStringNotContainsString('secret-sentinel', $result['stdout']);
    }

    public function testProcessRunnerUsesArgumentArraysAndEnforcesTimeout(): void
    {
        $argument = 'value with spaces & shell metacharacters';
        $argumentResult = \mysqlInstallRehearsalRunProcess(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, $argv[1]);', $argument],
            dirname(__DIR__, 2),
            \mysqlInstallRehearsalCleanProcessEnvironment([]),
            10,
        );

        self::assertSame(0, $argumentResult['exit_code'], $this->diagnostic($argumentResult));
        self::assertFalse($argumentResult['timed_out']);
        self::assertSame($argument, $argumentResult['stdout']);
        self::assertSame('', $argumentResult['stderr']);

        $timeoutResult = \mysqlInstallRehearsalRunProcess(
            [PHP_BINARY, '-r', 'usleep(5_000_000);'],
            dirname(__DIR__, 2),
            \mysqlInstallRehearsalCleanProcessEnvironment([]),
            1,
        );

        self::assertSame(124, $timeoutResult['exit_code'], $this->diagnostic($timeoutResult));
        self::assertTrue($timeoutResult['timed_out']);
        self::assertSame('', $timeoutResult['stdout']);
        self::assertSame('', $timeoutResult['stderr']);
    }

    public function testWorkflowAndComposerScriptsFormAnExecutablePinnedContract(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = Yaml::parseFile($root . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'workflows' . DIRECTORY_SEPARATOR . 'mysql-install-rehearsal.yml');
        $composer = json_decode(
            (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($workflow);
        self::assertIsArray($composer);
        $job = $workflow['jobs']['mysql-install-rehearsal'] ?? null;
        self::assertIsArray($job);
        self::assertSame('ubuntu-latest', $job['runs-on'] ?? null);
        self::assertSame('mysql:8.4', $job['services']['mysql']['image'] ?? null);
        $servicePassword = $job['services']['mysql']['env']['MYSQL_ROOT_PASSWORD'] ?? null;

        $steps = [];
        foreach ($job['steps'] ?? [] as $step) {
            if (is_array($step) && isset($step['name'])) {
                $steps[(string) $step['name']] = $step;
            }
        }
        self::assertSame('8.4.22', $steps['Set up PHP 8.4']['with']['php-version'] ?? null);
        self::assertSame(
            'composer run test:mysql-install-rehearsal',
            $steps['Run executable installer gate']['run'] ?? null,
        );
        self::assertSame('127.0.0.1', $steps['Run executable installer gate']['env']['MYSQL_REHEARSAL_HOST'] ?? null);
        self::assertSame('root', $steps['Run executable installer gate']['env']['MYSQL_REHEARSAL_USERNAME'] ?? null);
        self::assertSame(
            '${{ format(\'vertoad-ci-{0}-{1}\', github.run_id, github.run_attempt) }}',
            $servicePassword,
        );
        self::assertSame(
            $servicePassword,
            $steps['Run executable installer gate']['env']['MYSQL_REHEARSAL_PASSWORD'] ?? null,
        );
        self::assertSame(
            '@php tests/Install/mysql-install-rehearsal.php',
            $composer['scripts']['rehearse:mysql-install'] ?? null,
        );
        self::assertSame(
            'phpunit tests/Install/MysqlInstallRehearsalContractTest.php --fail-on-skipped --display-skipped',
            $composer['scripts']['test:mysql-install-rehearsal'] ?? null,
        );
        self::assertStringContainsString(
            '--exclude-group external-tools-integration',
            (string) ($composer['scripts']['test'] ?? ''),
        );
        self::assertSame(
            'phpunit tests/Integration/ExternalTools --fail-on-skipped --fail-on-warning --fail-on-risky',
            $composer['scripts']['test:external-tools-integration'] ?? null,
        );
    }

    #[Group('mysql-install-rehearsal')]
    public function testRealMysqlInstallationRunsInSubprocessAndLeavesNoDatabaseOrSecretArtifacts(): void
    {
        if (getenv('VERTOAD_MYSQL_INSTALL_REHEARSAL') !== '1') {
            self::markTestSkipped('Set VERTOAD_MYSQL_INSTALL_REHEARSAL=1 to opt in to the real MySQL installation rehearsal.');
        }

        $credentials = $this->realMysqlCredentials();
        $password = $credentials['MYSQL_REHEARSAL_PASSWORD'];
        $result = $this->runRehearsal([
            ...$credentials,
            'VERTOAD_MYSQL_INSTALL_REHEARSAL' => '1',
            'APP_ENV' => 'polluted-parent-environment',
            'APP_DEBUG' => 'true',
            'APP_INSTALLED' => 'false',
            'DATABASE_URL' => 'mysql://polluted.invalid/polluted',
            'INSTALL_TOKEN' => 'polluted-install-token',
            'DB_DRIVER' => 'pdo_sqlite',
            'DB_HOST' => 'polluted.invalid',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'polluted_database',
            'DB_USERNAME' => 'polluted_user',
            'DB_PASSWORD' => 'polluted_password',
            'DB_CHARSET' => 'latin1',
            'OAUTH_PRIVATE_KEY_PATH' => 'polluted/private.key',
            'OAUTH_PUBLIC_KEY_PATH' => 'polluted/public.key',
            'OAUTH_ENCRYPTION_KEY' => 'polluted-oauth-key',
        ]);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(0, $result['exit_code'], $this->diagnostic($result, $password));
        self::assertFalse($result['timed_out']);
        self::assertSame('vertoad.mysql-install-rehearsal.v1', $evidence['schema']);
        self::assertSame('passed', $evidence['status']);
        self::assertSame(0, $evidence['exit_code']);
        self::assertTrue($evidence['process_environment_isolated']);
        self::assertSame(8, $evidence['mysql']['major_version']);
        self::assertMatchesRegularExpression('/^8\./', (string) $evidence['mysql']['version']);
        self::assertNull($evidence['failure']);
        self::assertSame([], $evidence['cleanup_failures']);

        $databaseName = (string) $evidence['artifacts']['database_name'];
        self::assertMatchesRegularExpression('/^vertoad_install_rehearsal_[a-f0-9]{32}$/', $databaseName);
        self::assertGreaterThan(0, $evidence['installation']['migration_count']);
        self::assertGreaterThan(0, $evidence['installation']['permission_count']);
        self::assertGreaterThan(0, $evidence['installation']['config_count']);
        self::assertSame(410, $evidence['installation']['installed_route_status']);

        $runtime = $evidence['installation']['runtime_probe'];
        self::assertIsArray($runtime);
        self::assertSame('vertoad.mysql-install-runtime-probe.v1', $runtime['schema']);
        self::assertSame('passed', $runtime['status']);
        self::assertTrue($runtime['environment_file_loaded']);
        self::assertSame('production', $runtime['app_env']);
        self::assertTrue($runtime['app_installed']);
        self::assertFalse($runtime['app_debug']);
        self::assertSame($databaseName, $runtime['database_name']);
        self::assertSame($databaseName, $runtime['connected_database']);
        self::assertSame($databaseName, $runtime['runtime_connected_database']);
        self::assertTrue($runtime['database_url_empty']);
        self::assertTrue($runtime['install_token_empty']);
        self::assertTrue($runtime['app_key_valid']);
        self::assertTrue($runtime['oauth_private_key_valid']);
        self::assertTrue($runtime['oauth_public_key_valid']);
        self::assertTrue($runtime['oauth_key_pair_matches']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $runtime['oauth_public_key_sha256']);
        self::assertSame(410, $runtime['installed_route_status']);

        self::assertTrue($evidence['cleanup']['database_was_created']);
        self::assertTrue($evidence['cleanup']['database_drop_attempted']);
        self::assertTrue($evidence['cleanup']['database_absence_verified']);
        self::assertTrue($evidence['cleanup']['workspace_root_absent']);
        self::assertTrue($evidence['cleanup']['temporary_root_absent']);
        self::assertTrue($evidence['cleanup']['external_environment_absent']);
        self::assertTrue($evidence['cleanup']['external_environment_temporary_files_absent']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
        $this->assertDatabaseIsAbsent($databaseName, $credentials);

        self::assertArrayNotHasKey('password', $evidence);
        self::assertStringNotContainsString('DB_PASSWORD', $result['stdout']);
        // Short passwords can legitimately occur inside words such as "temporary_root".
        if (strlen($password) >= 8) {
            self::assertStringNotContainsString($password, $result['stdout']);
            self::assertStringNotContainsString($password, $result['stderr']);
        }
    }

    #[Group('mysql-install-rehearsal')]
    public function testRealMysqlFailureAfterInstallerCommitStillRemovesDatabaseAndSecretFiles(): void
    {
        if (getenv('VERTOAD_MYSQL_INSTALL_REHEARSAL') !== '1') {
            self::markTestSkipped('Set VERTOAD_MYSQL_INSTALL_REHEARSAL=1 to opt in to the real MySQL installation rehearsal.');
        }

        $credentials = $this->realMysqlCredentials();
        $password = $credentials['MYSQL_REHEARSAL_PASSWORD'];
        $result = $this->runRehearsal([
            ...$credentials,
            'VERTOAD_MYSQL_INSTALL_REHEARSAL' => '1',
            'MYSQL_REHEARSAL_FAULT_STAGE' => 'after_installer_execute',
        ]);
        $evidence = $this->decodeEvidence($result['stdout']);

        self::assertSame(1, $result['exit_code'], $this->diagnostic($result, $password));
        self::assertFalse($result['timed_out']);
        self::assertSame('failed', $evidence['status']);
        self::assertSame(1, $evidence['exit_code']);
        self::assertTrue($evidence['process_environment_isolated']);
        self::assertSame('installer_execute', $evidence['failure']['stage']);
        self::assertStringContainsString('after_installer_execute', $evidence['failure']['message']);
        self::assertSame([], $evidence['cleanup_failures']);

        $databaseName = (string) $evidence['artifacts']['database_name'];
        self::assertMatchesRegularExpression('/^vertoad_install_rehearsal_[a-f0-9]{32}$/', $databaseName);
        self::assertTrue($evidence['cleanup']['database_was_created']);
        self::assertTrue($evidence['cleanup']['database_drop_attempted']);
        self::assertTrue($evidence['cleanup']['database_absence_verified']);
        self::assertTrue($evidence['cleanup']['workspace_root_absent']);
        self::assertTrue($evidence['cleanup']['temporary_root_absent']);
        self::assertTrue($evidence['cleanup']['external_environment_absent']);
        self::assertTrue($evidence['cleanup']['external_environment_temporary_files_absent']);
        $this->assertFilesystemArtifactsAreAbsent($evidence);
        $this->assertDatabaseIsAbsent($databaseName, $credentials);
        if (strlen($password) >= 8) {
            self::assertStringNotContainsString($password, $result['stdout']);
            self::assertStringNotContainsString($password, $result['stderr']);
        }
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string> $removedVariables
     * @return array{exit_code: int, stdout: string, stderr: string, timed_out: bool}
     */
    private function runRehearsal(array $overrides, array $removedVariables = []): array
    {
        $environment = \mysqlInstallRehearsalCleanProcessEnvironment([]);
        foreach ($removedVariables as $name) {
            foreach (array_keys($environment) as $existingName) {
                if (strcasecmp($existingName, $name) === 0) {
                    unset($environment[$existingName]);
                }
            }
        }
        foreach ($overrides as $name => $value) {
            foreach (array_keys($environment) as $existingName) {
                if (strcasecmp($existingName, $name) === 0) {
                    unset($environment[$existingName]);
                }
            }
            $environment[$name] = $value;
        }

        return \mysqlInstallRehearsalRunProcess(
            [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'mysql-install-rehearsal.php'],
            dirname(__DIR__, 2),
            $environment,
            300,
        );
    }

    /** @return array<string, mixed> */
    private function decodeEvidence(string $stdout): array
    {
        try {
            $evidence = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Rehearsal stdout was not valid JSON evidence: ' . $exception->getMessage());
        }

        self::assertIsArray($evidence);

        return $evidence;
    }

    /** @param array<string, mixed> $evidence */
    private function assertFilesystemArtifactsAreAbsent(array $evidence): void
    {
        $workspaceRoot = (string) ($evidence['artifacts']['workspace_root'] ?? '');
        $temporaryRoot = (string) ($evidence['artifacts']['temporary_root'] ?? '');
        $environmentFile = (string) ($evidence['artifacts']['external_environment_file'] ?? '');
        self::assertNotSame('', $workspaceRoot);
        self::assertNotSame('', $temporaryRoot);
        self::assertNotSame('', $environmentFile);
        self::assertFileDoesNotExist($workspaceRoot);
        self::assertFileDoesNotExist($temporaryRoot);
        self::assertFileDoesNotExist($environmentFile);
        self::assertSame([], glob(
            dirname($environmentFile) . DIRECTORY_SEPARATOR . '.' . basename($environmentFile) . '.*.tmp',
        ) ?: []);
    }

    /**
     * @param array<string, string> $credentials
     */
    private function assertDatabaseIsAbsent(string $databaseName, #[\SensitiveParameter] array $credentials): void
    {
        $sslCaPath = trim($credentials['MYSQL_REHEARSAL_SSL_CA'] ?? '');
        $connection = \mysqlInstallRehearsalConnect(
            [
                'host' => $credentials['MYSQL_REHEARSAL_HOST'],
                'port' => (int) $credentials['MYSQL_REHEARSAL_PORT'],
                'username' => $credentials['MYSQL_REHEARSAL_USERNAME'],
                'password' => $credentials['MYSQL_REHEARSAL_PASSWORD'],
            ],
            \mysqlInstallRehearsalDriverOptions($sslCaPath === '' ? null : $sslCaPath, 5),
            'mysql',
        );
        try {
            self::assertSame(0, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
                [$databaseName],
            ));
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array{MYSQL_REHEARSAL_HOST: string, MYSQL_REHEARSAL_PORT: string, MYSQL_REHEARSAL_USERNAME: string, MYSQL_REHEARSAL_PASSWORD: string, MYSQL_REHEARSAL_SSL_CA?: string}
     */
    private function realMysqlCredentials(): array
    {
        $credentials = [];
        foreach (['MYSQL_REHEARSAL_HOST', 'MYSQL_REHEARSAL_PORT', 'MYSQL_REHEARSAL_USERNAME', 'MYSQL_REHEARSAL_PASSWORD'] as $name) {
            $value = getenv($name);
            if (!is_string($value) || ($name !== 'MYSQL_REHEARSAL_PASSWORD' && trim($value) === '') || $value === '') {
                self::markTestSkipped($name . ' must be set for the real MySQL installation rehearsal.');
            }
            $credentials[$name] = $value;
        }
        $sslCaPath = getenv('MYSQL_REHEARSAL_SSL_CA');
        if (is_string($sslCaPath) && trim($sslCaPath) !== '') {
            $credentials['MYSQL_REHEARSAL_SSL_CA'] = $sslCaPath;
        }

        /** @var array{MYSQL_REHEARSAL_HOST: string, MYSQL_REHEARSAL_PORT: string, MYSQL_REHEARSAL_USERNAME: string, MYSQL_REHEARSAL_PASSWORD: string, MYSQL_REHEARSAL_SSL_CA?: string} $credentials */
        return $credentials;
    }

    /**
     * @param array{exit_code: int, stdout: string, stderr: string, timed_out: bool} $result
     */
    private function diagnostic(array $result, string $password = ''): string
    {
        return \mysqlInstallRehearsalRedact(sprintf(
            "exit=%d timed_out=%s\nstdout:\n%s\nstderr:\n%s",
            $result['exit_code'],
            $result['timed_out'] ? 'true' : 'false',
            $result['stdout'],
            $result['stderr'],
        ), [$password]);
    }
}
