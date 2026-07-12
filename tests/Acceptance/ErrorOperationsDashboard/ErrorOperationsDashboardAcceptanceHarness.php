<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance\ErrorOperationsDashboard;

use DateTimeImmutable;
use DateTimeZone;
use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SensitiveParameter;
use Throwable;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Service\Archive\ProcessArchiveCommandRunner;
use VertoAD\Service\PasswordHasher;

final readonly class ErrorOperationsDashboardAcceptanceConfig
{
    private function __construct(
        public string $mysqlHost,
        public int $mysqlPort,
        public string $mysqlUsername,
        #[SensitiveParameter]
        private string $mysqlPassword,
        public string $redisDriver,
        public string $redisHost,
        public int $redisPort,
        #[SensitiveParameter]
        private string $redisPassword,
        public int $redisDatabase,
        public float $redisTimeoutSeconds,
        public float $redisReadTimeoutSeconds,
        public string $nodeBinary,
        public ?string $browserExecutable,
        public int $commandTimeoutSeconds,
    ) {
    }

    public static function fromEnvironment(): self
    {
        if (self::environment('DB_DRIVER', 'pdo_mysql') !== 'pdo_mysql') {
            throw new \RuntimeException('The error operations acceptance runner requires DB_DRIVER=pdo_mysql.');
        }

        $mysqlUsername = self::acceptanceEnvironment('DB_USERNAME', 'DB_USERNAME', '');
        if ($mysqlUsername === '') {
            throw new \RuntimeException('A MySQL acceptance username is required.');
        }
        $redisPassword = self::acceptanceSecretEnvironment('REDIS_PASSWORD', 'REDIS_PASSWORD');
        if ($redisPassword === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for error operations acceptance.');
        }

        return new self(
            mysqlHost: self::acceptanceEnvironment('DB_HOST', 'DB_HOST', '127.0.0.1'),
            mysqlPort: self::positiveInt(self::acceptanceEnvironment('DB_PORT', 'DB_PORT', '3306'), 'MySQL port'),
            mysqlUsername: $mysqlUsername,
            mysqlPassword: self::acceptanceSecretEnvironment('DB_PASSWORD', 'DB_PASSWORD'),
            redisDriver: self::acceptanceEnvironment('REDIS_DRIVER', 'REDIS_DRIVER', 'auto'),
            redisHost: self::acceptanceEnvironment('REDIS_HOST', 'REDIS_HOST', '127.0.0.1'),
            redisPort: self::positiveInt(self::acceptanceEnvironment('REDIS_PORT', 'REDIS_PORT', '6379'), 'Redis port'),
            redisPassword: $redisPassword,
            redisDatabase: self::nonNegativeInt(self::acceptanceEnvironment('REDIS_DATABASE', 'REDIS_DATABASE', '0'), 'Redis database'),
            redisTimeoutSeconds: self::positiveFloat(self::acceptanceEnvironment('REDIS_TIMEOUT_SECONDS', 'REDIS_TIMEOUT_SECONDS', '2'), 'Redis timeout'),
            redisReadTimeoutSeconds: self::positiveFloat(self::acceptanceEnvironment('REDIS_READ_TIMEOUT_SECONDS', 'REDIS_READ_TIMEOUT_SECONDS', '2'), 'Redis read timeout'),
            nodeBinary: self::environment('NODE_BINARY', 'node'),
            browserExecutable: self::browserExecutable(),
            commandTimeoutSeconds: self::positiveInt(
                self::environment('VERTOAD_ERROR_OPERATIONS_DASHBOARD_ACCEPTANCE_TIMEOUT_SECONDS', '180'),
                'Browser command timeout',
            ),
        );
    }

    /** @return array<string, mixed> */
    public function mysqlSettings(string $database): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => $this->mysqlHost,
            'port' => $this->mysqlPort,
            'database' => $database,
            'username' => $this->mysqlUsername,
            'password' => $this->mysqlPassword,
            'charset' => 'utf8mb4',
        ];
    }

    /** @return array<string, mixed> */
    public function redisSettings(string $prefix): array
    {
        return [
            'driver' => $this->redisDriver,
            'host' => $this->redisHost,
            'port' => $this->redisPort,
            'password' => $this->redisPassword,
            'database' => $this->redisDatabase,
            'prefix' => $prefix,
            'timeout_seconds' => $this->redisTimeoutSeconds,
            'read_timeout_seconds' => $this->redisReadTimeoutSeconds,
        ];
    }

    public function mysqlAdminPdo(): PDO
    {
        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->mysqlHost, $this->mysqlPort),
                $this->mysqlUsername,
                $this->mysqlPassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5,
                ],
            );
        } catch (Throwable) {
            throw new \RuntimeException('Unable to connect to the MySQL error operations acceptance control plane.');
        }
    }

    /** @param list<string> $additionalSecrets */
    public function redact(string $value, array $additionalSecrets = []): string
    {
        $secrets = array_values(array_filter(
            [$this->mysqlPassword, $this->redisPassword, ...$additionalSecrets],
            static fn (string $secret): bool => $secret !== '',
        ));

        return $secrets === [] ? $value : str_replace($secrets, '[redacted]', $value);
    }

    private static function browserExecutable(): ?string
    {
        $configured = self::environment('PLAYWRIGHT_CHROMIUM_EXECUTABLE', '');
        if ($configured !== '') {
            if (!is_file($configured)) {
                throw new \RuntimeException('PLAYWRIGHT_CHROMIUM_EXECUTABLE must name an existing browser executable.');
            }

            return $configured;
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            return null;
        }

        foreach (['ProgramFiles', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $variable) {
            $base = getenv($variable);
            if ($base === false || trim((string) $base) === '') {
                continue;
            }
            $candidate = rtrim((string) $base, "\\/") . DIRECTORY_SEPARATOR
                . 'Google' . DIRECTORY_SEPARATOR . 'Chrome' . DIRECTORY_SEPARATOR
                . 'Application' . DIRECTORY_SEPARATOR . 'chrome.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function acceptanceEnvironment(string $suffix, string $fallback, string $default): string
    {
        $value = self::environment('VERTOAD_ERROR_OPERATIONS_DASHBOARD_ACCEPTANCE_' . $suffix, '');

        return $value === '' ? self::environment($fallback, $default) : $value;
    }

    private static function acceptanceSecretEnvironment(string $suffix, string $fallback): string
    {
        $value = getenv('VERTOAD_ERROR_OPERATIONS_DASHBOARD_ACCEPTANCE_' . $suffix);

        return $value === false ? (string) (getenv($fallback) ?: '') : (string) $value;
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : trim((string) $value);
    }

    private static function positiveInt(string $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new \RuntimeException($label . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private static function nonNegativeInt(string $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new \RuntimeException($label . ' must be a non-negative integer.');
        }

        return (int) $value;
    }

    private static function positiveFloat(string $value, string $label): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new \RuntimeException($label . ' must be positive.');
        }

        return (float) $value;
    }
}

final class ErrorOperationsDashboardAcceptanceHarness
{
    private const DATABASE_PATTERN = '/^vertoad_error_ops_[a-f0-9]{16}$/D';
    private const REDIS_PREFIX_PATTERN = '/^vertoad:acceptance:error-operations:[a-f0-9]{16}:$/D';
    private const REDIS_SCAN_PAGE_SIZE = 100;

    private ?PDO $mysqlAdmin = null;
    private ?Connection $connection = null;
    private ?RedisClientInterface $redis = null;
    private mixed $serverProcess = null;
    private bool $databaseCreated = false;
    private bool $redisPrefixOwned = false;
    private bool $cleaned = false;
    /** @var array<string, array{process:string|false,env_exists:bool,env:mixed,server_exists:bool,server:mixed}> */
    private array $environmentBackup = [];
    /** @var array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int,server_stopped:int}|null */
    private ?array $cleanupEvidence = null;
    private int $superAdminUserId;
    private int $operatorUserId;
    private int $operatorOrganizationId;
    private string $mysqlVersion = '';

    private function __construct(
        private readonly string $rootPath,
        private readonly string $frontendPath,
        private readonly ErrorOperationsDashboardAcceptanceConfig $config,
        private readonly string $runId,
        private readonly string $databaseName,
        private readonly string $redisPrefix,
        private readonly int $apiPort,
        private readonly int $uiPort,
        private readonly string $workspace,
        private readonly string $frontendRuntimeWorkspace,
        private readonly string $screenshotPath,
        private readonly string $superAdminEmail,
        #[SensitiveParameter]
        private readonly string $superAdminPassword,
        private readonly string $operatorEmail,
        #[SensitiveParameter]
        private readonly string $operatorPassword,
        #[SensitiveParameter]
        private readonly string $contextMarker,
        #[SensitiveParameter]
        private readonly string $appKey,
    ) {
    }

    public static function boot(string $rootPath): self
    {
        EnvironmentLoader::load($rootPath);
        $config = ErrorOperationsDashboardAcceptanceConfig::fromEnvironment();
        $runId = bin2hex(random_bytes(8));
        $frontendPath = dirname($rootPath) . DIRECTORY_SEPARATOR . 'frontend';
        $apiPort = self::reserveLoopbackPort();
        do {
            $uiPort = self::reserveLoopbackPort();
        } while ($uiPort === $apiPort);

        $harness = new self(
            rootPath: $rootPath,
            frontendPath: $frontendPath,
            config: $config,
            runId: $runId,
            databaseName: 'vertoad_error_ops_' . $runId,
            redisPrefix: 'vertoad:acceptance:error-operations:' . $runId . ':',
            apiPort: $apiPort,
            uiPort: $uiPort,
            workspace: $rootPath . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR
                . 'error-operations-dashboard' . DIRECTORY_SEPARATOR . $runId,
            frontendRuntimeWorkspace: $frontendPath . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
                . '.tmp' . DIRECTORY_SEPARATOR . 'error-operations-dashboard-smoke' . DIRECTORY_SEPARATOR . 'runtime-' . $runId,
            screenshotPath: $frontendPath . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
                . '.tmp' . DIRECTORY_SEPARATOR . 'error-operations-dashboard-smoke' . DIRECTORY_SEPARATOR
                . 'operations-error-correlation-' . $runId . '.png',
            superAdminEmail: 'error-ops-super-' . $runId . '@example.test',
            superAdminPassword: 'Super-' . bin2hex(random_bytes(24)),
            operatorEmail: 'error-ops-operator-' . $runId . '@example.test',
            operatorPassword: 'Operator-' . bin2hex(random_bytes(24)),
            contextMarker: 'context-' . bin2hex(random_bytes(24)),
            appKey: Key::createNewRandomKey()->saveToAsciiSafeString(),
        );

        try {
            $harness->initialize();

            return $harness;
        } catch (Throwable $exception) {
            try {
                $harness->cleanup();
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function mysqlVersion(): string
    {
        return $this->mysqlVersion;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function redisPrefix(): string
    {
        return $this->redisPrefix;
    }

    public function migrationCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM phinxlog');
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            throw new \LogicException('The error operations acceptance connection is unavailable.');
        }

        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (Throwable) {
            $this->connection->close();
            $this->connection = (new ConnectionFactory())->create(
                $this->config->mysqlSettings($this->databaseName),
            );
            $this->connection->fetchOne('SELECT 1');
        }

        return $this->connection;
    }

    public function contextMarker(): string
    {
        return $this->contextMarker;
    }

    public function superAdminUserId(): int
    {
        return $this->superAdminUserId;
    }

    public function operatorUserId(): int
    {
        return $this->operatorUserId;
    }

    /** @return array<string, mixed> */
    public function runBrowserScenario(): array
    {
        $tsxCli = $this->frontendPath . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
            . 'tsx' . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cli.mjs';
        $script = $this->frontendPath . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'error-operations-dashboard-smoke.ts';
        if (!is_file($tsxCli) || !is_file($script)) {
            throw new \RuntimeException('The frontend error operations browser runner dependencies are unavailable.');
        }

        $result = (new ProcessArchiveCommandRunner())->run(
            [$this->config->nodeBinary, $tsxCli, $script],
            null,
            $this->config->commandTimeoutSeconds,
        );
        if ($result->exitCode !== 0) {
            $serverLogs = $this->readServerLogs();
            throw new \RuntimeException($this->redact(
                "Error operations browser runner failed with exit code {$result->exitCode}.\n"
                . trim($result->stdout . "\n" . $result->stderr)
                . ($serverLogs === '' ? '' : "\nPHP server logs:\n" . $serverLogs),
            ));
        }
        if (preg_match('/^ERROR_OPERATIONS_DASHBOARD_EVIDENCE=(\{.*\})$/m', $result->stdout, $matches) !== 1) {
            throw new \RuntimeException($this->redact(
                'Error operations browser runner did not emit parseable evidence. ' . trim($result->stdout),
            ));
        }

        $decoded = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Error operations browser evidence must be a JSON object.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function operatorVisibilityEvidence(string $requestId, string $errorId): array
    {
        $login = $this->httpJson('POST', '/api/v1/auth/login', [
            'email' => $this->operatorEmail,
            'password' => $this->operatorPassword,
        ]);
        $token = $login['body']['data']['token']['access_token'] ?? null;
        if ($login['status'] !== 200 || !is_string($token) || $token === '') {
            $error = $login['body']['error'] ?? null;
            $errorCode = is_array($error) && is_string($error['code'] ?? null)
                ? $error['code']
                : 'none';
            $requestId = is_string($login['body']['request_id'] ?? null)
                ? $login['body']['request_id']
                : 'missing';
            throw new \RuntimeException(sprintf(
                'The ordinary operations user could not obtain a real Bearer session. status=%d error_code=%s request_id=%s',
                $login['status'],
                $errorCode,
                $requestId,
            ));
        }
        $organization = '?organization_id=' . $this->operatorOrganizationId;
        $me = $this->httpJson('GET', '/api/v1/auth/me' . $organization, token: $token);
        $errors = $this->httpJson(
            'GET',
            '/api/v1/operations/errors' . $organization . '&request_id=' . rawurlencode($requestId),
            token: $token,
        );
        $correlation = $this->httpJson(
            'GET',
            '/api/v1/operations/request-correlations/' . rawurlencode($requestId) . $organization,
            token: $token,
        );
        $raw = $this->httpJson(
            'GET',
            '/api/v1/operations/errors/' . rawurlencode($errorId) . '/raw-context' . $organization,
            token: $token,
        );

        return [
            'login_status' => $login['status'],
            'me_status' => $me['status'],
            'is_super_admin' => $me['body']['data']['user']['is_super_admin'] ?? null,
            'errors' => $errors,
            'correlation' => $correlation,
            'raw' => $raw,
        ];
    }

    public function redisKeyCount(): int
    {
        $cursor = '0';
        $count = 0;
        $pages = 0;
        do {
            [$cursor, $matched] = $this->scanRedisPage($cursor, false);
            $count += $matched;
            if (++$pages > 100_000) {
                throw new \RuntimeException('Error operations Redis scan exceeded its page limit.');
            }
        } while ($cursor !== '0');

        return $count;
    }

    /**
     * @return array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int,server_stopped:int}
     */
    public function cleanup(): array
    {
        if ($this->cleaned) {
            return $this->cleanupEvidence ?? $this->emptyCleanupEvidence();
        }
        $this->cleaned = true;

        $redisKeysBefore = 0;
        $redisKeysDeleted = 0;
        $redisKeysAfter = 0;
        $databaseBefore = 0;
        $databaseAfter = 0;
        $workspaceBefore = is_dir($this->workspace) ? 1 : 0;
        $workspaceAfter = $workspaceBefore;
        $serverStopped = 0;
        $failure = null;

        try {
            $this->stopServer();
            $serverStopped = 1;
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        try {
            if ($this->redis !== null && $this->redisPrefixOwned) {
                $redisKeysBefore = $this->redisKeyCount();
                $redisKeysDeleted = $this->deleteRedisKeys();
                $redisKeysAfter = $this->redisKeyCount();
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        try {
            $this->connection?->close();
            $this->connection = null;
            if ($this->mysqlAdmin !== null && $this->databaseCreated) {
                $this->ensureMysqlAdminConnection();
                $databaseBefore = $this->databaseExists();
                $this->mysqlAdmin->exec('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
                $databaseAfter = $this->databaseExists();
            }
            $this->mysqlAdmin = null;
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        try {
            $this->removeDirectory($this->frontendRuntimeWorkspace);
            $this->removeDirectory($this->workspace);
            $workspaceAfter = is_dir($this->workspace) || is_dir($this->frontendRuntimeWorkspace) ? 1 : 0;
        } catch (Throwable $exception) {
            $failure ??= $exception;
        } finally {
            $this->restoreRuntimeEnvironment();
        }

        $this->cleanupEvidence = [
            'redis_keys_before' => $redisKeysBefore,
            'redis_keys_deleted' => $redisKeysDeleted,
            'redis_keys_after' => $redisKeysAfter,
            'database_before' => $databaseBefore,
            'database_after' => $databaseAfter,
            'workspace_before' => $workspaceBefore,
            'workspace_after' => $workspaceAfter,
            'server_stopped' => $serverStopped,
        ];
        if ($failure !== null) {
            throw new \RuntimeException('Error operations acceptance cleanup failed.', previous: $failure);
        }
        if ($redisKeysAfter !== 0 || $databaseAfter !== 0 || $workspaceAfter !== 0 || $serverStopped !== 1) {
            throw new \RuntimeException('Error operations acceptance left isolated resources behind.');
        }

        return $this->cleanupEvidence;
    }

    private function initialize(): void
    {
        $this->assertIdentity();
        $this->createWorkspace();
        $this->applyRuntimeEnvironment();
        $this->connectRedis();
        $this->createDatabase();
        $this->migrateAndSeed();
        $this->startServer();
    }

    private function assertIdentity(): void
    {
        if (preg_match(self::DATABASE_PATTERN, $this->databaseName) !== 1) {
            throw new \LogicException('Unsafe generated error operations database name.');
        }
        if (preg_match(self::REDIS_PREFIX_PATTERN, $this->redisPrefix) !== 1) {
            throw new \LogicException('Unsafe generated error operations Redis prefix.');
        }
    }

    private function createWorkspace(): void
    {
        if (file_exists($this->workspace) || is_link($this->workspace)) {
            throw new \RuntimeException('The error operations acceptance workspace already exists.');
        }
        if (!mkdir($this->workspace, 0700, true) && !is_dir($this->workspace)) {
            throw new \RuntimeException('Unable to create the error operations acceptance workspace.');
        }
    }

    private function applyRuntimeEnvironment(): void
    {
        $environment = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_INSTALLED' => 'true',
            'VERTOAD_INSTALL_TEST_BYPASS' => 'true',
            'APP_KEY' => $this->appKey,
            'DB_DRIVER' => 'pdo_mysql',
            'DB_HOST' => $this->config->mysqlHost,
            'DB_PORT' => (string) $this->config->mysqlPort,
            'DB_DATABASE' => $this->databaseName,
            'TEST_DB_DATABASE' => $this->databaseName,
            'DB_USERNAME' => $this->config->mysqlUsername,
            'DB_PASSWORD' => (string) $this->config->mysqlSettings($this->databaseName)['password'],
            'DB_CHARSET' => 'utf8mb4',
            'REDIS_DRIVER' => $this->config->redisDriver,
            'REDIS_HOST' => $this->config->redisHost,
            'REDIS_PORT' => (string) $this->config->redisPort,
            'REDIS_PASSWORD' => (string) $this->config->redisSettings($this->redisPrefix)['password'],
            'REDIS_DATABASE' => (string) $this->config->redisDatabase,
            'REDIS_PREFIX' => $this->redisPrefix,
            'REDIS_TIMEOUT_SECONDS' => (string) $this->config->redisTimeoutSeconds,
            'REDIS_READ_TIMEOUT_SECONDS' => (string) $this->config->redisReadTimeoutSeconds,
            'IP_GEO_REPOSITORY' => 'memory',
            'ARCHIVE_WRITER' => 'deterministic',
            'ARCHIVE_COLD_QUERY_RUNNER' => 'fixture',
            'CLOUDFLARE_TRUSTED_PROXIES' => '',
            'CORS_ALLOWED_ORIGINS' => $this->uiOrigin(),
            'TURNSTILE_SECRET_KEY' => '',
            'VERTOAD_ERROR_OPERATIONS_API_ORIGIN' => $this->apiOrigin(),
            'VERTOAD_ERROR_OPERATIONS_UI_PORT' => (string) $this->uiPort,
            'VERTOAD_ERROR_OPERATIONS_FRONTEND_PATH' => $this->frontendPath,
            'VERTOAD_ERROR_OPERATIONS_RUNTIME_WORKSPACE' => $this->frontendRuntimeWorkspace,
            'VERTOAD_ERROR_OPERATIONS_SCREENSHOT_PATH' => $this->screenshotPath,
            'VERTOAD_ERROR_OPERATIONS_SUPER_EMAIL' => $this->superAdminEmail,
            'VERTOAD_ERROR_OPERATIONS_SUPER_PASSWORD' => $this->superAdminPassword,
            'VERTOAD_ERROR_OPERATIONS_CONTEXT_MARKER' => $this->contextMarker,
            'NO_PROXY' => '127.0.0.1,localhost',
            'no_proxy' => '127.0.0.1,localhost',
        ];
        if ($this->config->browserExecutable !== null) {
            $environment['PLAYWRIGHT_CHROMIUM_EXECUTABLE'] = $this->config->browserExecutable;
        }

        foreach ($environment as $name => $value) {
            $this->backupAndSetEnvironment($name, (string) $value);
        }
    }

    private function backupAndSetEnvironment(string $name, string $value): void
    {
        $this->environmentBackup[$name] = [
            'process' => getenv($name),
            'env_exists' => array_key_exists($name, $_ENV),
            'env' => $_ENV[$name] ?? null,
            'server_exists' => array_key_exists($name, $_SERVER),
            'server' => $_SERVER[$name] ?? null,
        ];
        if (!putenv($name . '=' . $value)) {
            throw new \RuntimeException('Unable to configure the error operations acceptance environment.');
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function restoreRuntimeEnvironment(): void
    {
        foreach (array_reverse($this->environmentBackup, true) as $name => $previous) {
            if ($previous['process'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous['process']);
            }
            if ($previous['env_exists']) {
                $_ENV[$name] = $previous['env'];
            } else {
                unset($_ENV[$name]);
            }
            if ($previous['server_exists']) {
                $_SERVER[$name] = $previous['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }
        $this->environmentBackup = [];
    }

    private function connectRedis(): void
    {
        $this->redis = RedisClientFactory::fromSettings($this->config->redisSettings($this->redisPrefix));
        if ($this->redisKeyCount() !== 0) {
            throw new \RuntimeException('The generated error operations Redis prefix was not empty.');
        }
        $this->redisPrefixOwned = true;
        $probeKey = $this->redisPrefix . 'harness-probe';
        if (!$this->redis->setEx($probeKey, 'ok', 600) || $this->redis->get($probeKey) !== 'ok') {
            throw new \RuntimeException('The error operations Redis round trip failed.');
        }
    }

    private function createDatabase(): void
    {
        $this->mysqlAdmin = $this->config->mysqlAdminPdo();
        $version = (string) $this->mysqlAdmin->query('SELECT VERSION()')->fetchColumn();
        if (preg_match('/^(\d+)\./D', $version, $matches) !== 1 || (int) $matches[1] < 8) {
            throw new \RuntimeException('The error operations acceptance runner requires MySQL 8 or newer.');
        }
        $this->mysqlVersion = $version;
        $this->mysqlAdmin->exec(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->databaseCreated = true;
    }

    private function migrateAndSeed(): void
    {
        $settings = $this->config->mysqlSettings($this->databaseName);
        (new PhinxMigrationRunner($this->rootPath))->migrate($settings);
        $this->connection = (new ConnectionFactory())->create($settings);
        $this->seed($this->connection);
    }

    private function seed(Connection $connection): void
    {
        $hasher = new PasswordHasher();
        $connection->insert('users', [
            'email' => $this->superAdminEmail,
            'password_hash' => $hasher->hash($this->superAdminPassword),
            'display_name' => 'Error operations super administrator',
            'status' => 'active',
        ]);
        $this->superAdminUserId = (int) $connection->lastInsertId();
        $connection->insert('roles', [
            'organization_id' => null,
            'name' => 'Super administrator',
            'slug' => 'super-admin',
            'description' => 'Acceptance global super administrator',
            'is_system' => 1,
        ]);
        $superRoleId = (int) $connection->lastInsertId();
        $connection->insert('user_roles', [
            'user_id' => $this->superAdminUserId,
            'role_id' => $superRoleId,
            'organization_id' => null,
        ]);

        $connection->insert('organizations', [
            'name' => 'Error operations tenant',
            'slug' => 'error-operations-' . $this->runId,
            'billing_status' => 'active',
        ]);
        $this->operatorOrganizationId = (int) $connection->lastInsertId();
        $connection->insert('users', [
            'email' => $this->operatorEmail,
            'password_hash' => $hasher->hash($this->operatorPassword),
            'display_name' => 'Error operations viewer',
            'status' => 'active',
        ]);
        $this->operatorUserId = (int) $connection->lastInsertId();
        $connection->insert('organization_members', [
            'organization_id' => $this->operatorOrganizationId,
            'user_id' => $this->operatorUserId,
            'status' => 'active',
            'title' => 'Operations viewer',
        ]);
        $connection->insert('roles', [
            'organization_id' => $this->operatorOrganizationId,
            'name' => 'Operations viewer',
            'slug' => 'operations-viewer',
            'description' => 'Acceptance platform operations viewer',
            'is_system' => 0,
        ]);
        $operatorRoleId = (int) $connection->lastInsertId();
        $connection->insert('user_roles', [
            'user_id' => $this->operatorUserId,
            'role_id' => $operatorRoleId,
            'organization_id' => $this->operatorOrganizationId,
        ]);
        foreach ([
            'ops.dashboard.read.platform',
            'ops.error_log.read_redacted.platform',
            'ops.error_log.view_raw.platform',
            'config.read.platform',
            'webhook.delivery.read.platform',
        ] as $permission) {
            $connection->insert('permissions', ['slug' => $permission, 'description' => 'Acceptance permission']);
            $connection->insert('role_permissions', [
                'role_id' => $operatorRoleId,
                'permission_id' => (int) $connection->lastInsertId(),
            ]);
        }
    }

    private function startServer(): void
    {
        $router = __DIR__ . DIRECTORY_SEPARATOR . 'error-operations-dashboard-router.php';
        if (!is_file($router)) {
            throw new \RuntimeException('The error operations PHP HTTP router is missing.');
        }
        $stdout = $this->workspace . DIRECTORY_SEPARATOR . 'php-server.stdout.log';
        $stderr = $this->workspace . DIRECTORY_SEPARATOR . 'php-server.stderr.log';
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $this->serverProcess = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->apiPort, $router],
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $stdout, 'a'],
                2 => ['file', $stderr, 'a'],
            ],
            $pipes,
            $this->rootPath,
            $this->processEnvironment(),
        );
        if (!is_resource($this->serverProcess)) {
            throw new \RuntimeException('Unable to start the PHP error operations HTTP server.');
        }

        $lastError = null;
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $status = proc_get_status($this->serverProcess);
            if (($status['running'] ?? false) !== true) {
                break;
            }
            try {
                $health = $this->httpJson('GET', '/__acceptance/health', timeoutSeconds: 5);
                if ($health['status'] === 200 && ($health['body']['ready'] ?? false) === true) {
                    return;
                }
            } catch (Throwable $exception) {
                $lastError = $exception;
            }
            usleep(100_000);
        }

        $logs = $this->readServerLogs();
        throw new \RuntimeException($this->redact(
            'The PHP error operations HTTP server did not become ready. '
            . ($lastError?->getMessage() ?? '') . "\n" . $logs,
        ));
    }

    private function stopServer(): void
    {
        if (!is_resource($this->serverProcess)) {
            $this->serverProcess = null;

            return;
        }
        $status = proc_get_status($this->serverProcess);
        $processId = isset($status['pid']) ? (int) $status['pid'] : 0;
        if (($status['running'] ?? false) === true) {
            proc_terminate($this->serverProcess);
            for ($attempt = 0; $attempt < 50; ++$attempt) {
                usleep(100_000);
                $status = proc_get_status($this->serverProcess);
                if (($status['running'] ?? false) !== true) {
                    break;
                }
            }
        }
        $status = proc_get_status($this->serverProcess);
        if (($status['running'] ?? false) === true) {
            proc_terminate($this->serverProcess, 9);
        }
        if (DIRECTORY_SEPARATOR === '\\' && $processId > 0 && $this->windowsProcessIsRunning($processId)) {
            $output = [];
            $exitCode = 0;
            exec('taskkill /PID ' . $processId . ' /T /F >NUL 2>&1', $output, $exitCode);
            for ($attempt = 0; $attempt < 50 && $this->windowsProcessIsRunning($processId); ++$attempt) {
                usleep(100_000);
            }
        }
        proc_close($this->serverProcess);
        $this->serverProcess = null;
        if (DIRECTORY_SEPARATOR === '\\' && $processId > 0 && $this->windowsProcessIsRunning($processId)) {
            throw new \RuntimeException('The PHP error operations HTTP server did not stop.');
        }
    }

    private function windowsProcessIsRunning(int $processId): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\' || $processId <= 0) {
            return false;
        }
        $output = [];
        $exitCode = 0;
        exec('tasklist /FI "PID eq ' . $processId . '" /NH /FO CSV 2>NUL', $output, $exitCode);
        if ($exitCode !== 0) {
            return false;
        }

        return array_any(
            $output,
            static fn (string $line): bool => str_contains($line, ',"' . $processId . '",'),
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status:int,headers:array<string,string>,body:array<string,mixed>}
     */
    private function httpJson(
        string $method,
        string $path,
        ?array $body = null,
        ?string $token = null,
        int $timeoutSeconds = 180,
    ): array
    {
        $headers = ['Accept: application/json'];
        $content = '';
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'timeout' => $timeoutSeconds,
        ]]);
        $responseHeaders = [];
        $raw = @file_get_contents($this->apiOrigin() . $path, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            $responseHeaders = $http_response_header;
        }
        if ($raw === false || $responseHeaders === []) {
            throw new \RuntimeException('The error operations HTTP request failed.');
        }
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $matches) !== 1) {
            throw new \RuntimeException('The error operations HTTP response omitted its status.');
        }
        $normalizedHeaders = [];
        foreach (array_slice($responseHeaders, 1) as $header) {
            if (!str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            $normalizedHeaders[strtolower(trim($name))] = trim($value);
        }
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The error operations HTTP response was not a JSON object.');
        }

        return ['status' => (int) $matches[1], 'headers' => $normalizedHeaders, 'body' => $decoded];
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        $environment = getenv();
        if (!is_array($environment)) {
            throw new \RuntimeException('Unable to read the process environment for the PHP HTTP server.');
        }

        return array_map('strval', $environment);
    }

    private function readServerLogs(): string
    {
        $contents = [];
        foreach (['php-server.stdout.log', 'php-server.stderr.log'] as $name) {
            $path = $this->workspace . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $contents[] = (string) file_get_contents($path);
            }
        }

        return trim(implode("\n", $contents));
    }

    private function apiOrigin(): string
    {
        return 'http://127.0.0.1:' . $this->apiPort;
    }

    private function uiOrigin(): string
    {
        return 'http://127.0.0.1:' . $this->uiPort;
    }

    /** @return array{0:string,1:int} */
    private function scanRedisPage(string $cursor, bool $delete): array
    {
        $result = $this->redis()->eval(
            <<<'LUA'
local result = redis.call('SCAN', ARGV[1], 'MATCH', ARGV[2] .. '*', 'COUNT', ARGV[3])
local keys = result[2]
local deleted = 0
if ARGV[4] == '1' and #keys > 0 then
    deleted = redis.call('DEL', unpack(keys))
end
return {result[1], tostring(#keys), tostring(deleted)}
LUA,
            [],
            [$cursor, $this->redisPrefix, (string) self::REDIS_SCAN_PAGE_SIZE, $delete ? '1' : '0'],
        );
        if (count($result) !== 3 || !ctype_digit($result[0]) || !ctype_digit($result[1]) || !ctype_digit($result[2])) {
            throw new \RuntimeException('Error operations Redis scan returned an invalid response.');
        }

        return [$result[0], $delete ? (int) $result[2] : (int) $result[1]];
    }

    private function deleteRedisKeys(): int
    {
        $deleted = 0;
        for ($pass = 0; $pass < 20; ++$pass) {
            $cursor = '0';
            do {
                [$cursor, $pageDeleted] = $this->scanRedisPage($cursor, true);
                $deleted += $pageDeleted;
            } while ($cursor !== '0');
            if ($this->redisKeyCount() === 0) {
                return $deleted;
            }
        }

        throw new \RuntimeException('Error operations Redis cleanup left prefixed keys behind.');
    }

    private function redis(): RedisClientInterface
    {
        return $this->redis ?? throw new \LogicException('The error operations Redis client is unavailable.');
    }

    private function databaseExists(): int
    {
        $this->ensureMysqlAdminConnection();
        $statement = $this->mysqlAdmin?->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :database_name',
        );
        if ($statement === null) {
            return 0;
        }
        $statement->execute(['database_name' => $this->databaseName]);

        return (int) $statement->fetchColumn();
    }

    private function ensureMysqlAdminConnection(): void
    {
        if ($this->mysqlAdmin === null) {
            $this->mysqlAdmin = $this->config->mysqlAdminPdo();

            return;
        }

        try {
            $this->mysqlAdmin->query('SELECT 1')->fetchColumn();
        } catch (Throwable) {
            $this->mysqlAdmin = $this->config->mysqlAdminPdo();
            $this->mysqlAdmin->query('SELECT 1')->fetchColumn();
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException('Unable to remove an error operations workspace file.');
            }

            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to remove the error operations workspace.');
        }
    }

    private function redact(string $value): string
    {
        return $this->config->redact($value, [
            $this->superAdminPassword,
            $this->operatorPassword,
            $this->contextMarker,
        ]);
    }

    /** @return array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int,server_stopped:int} */
    private function emptyCleanupEvidence(): array
    {
        return [
            'redis_keys_before' => 0,
            'redis_keys_deleted' => 0,
            'redis_keys_after' => 0,
            'database_before' => 0,
            'database_after' => 0,
            'workspace_before' => 0,
            'workspace_after' => 0,
            'server_stopped' => 1,
        ];
    }

    private static function reserveLoopbackPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new \RuntimeException('Unable to reserve a loopback port: ' . $errorMessage, $errorCode);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
            throw new \RuntimeException('The reserved loopback port was invalid.');
        }

        return (int) $matches[1];
    }
}
