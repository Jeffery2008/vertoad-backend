<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance\AttributionBrowser;

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
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Service\Archive\ProcessArchiveCommandRunner;
use Slim\Psr7\Factory\ServerRequestFactory;

final readonly class AttributionBrowserAcceptanceConfig
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
        $driver = self::environment('DB_DRIVER', 'pdo_mysql');
        if ($driver !== 'pdo_mysql') {
            throw new \RuntimeException('The attribution browser acceptance runner requires DB_DRIVER=pdo_mysql.');
        }

        $mysqlUsername = self::acceptanceEnvironment('DB_USERNAME', 'DB_USERNAME', '');
        if ($mysqlUsername === '') {
            throw new \RuntimeException('A MySQL acceptance username is required.');
        }

        $redisPassword = self::acceptanceSecretEnvironment('REDIS_PASSWORD', 'REDIS_PASSWORD');
        if ($redisPassword === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for attribution browser acceptance.');
        }

        return new self(
            mysqlHost: self::acceptanceEnvironment('DB_HOST', 'DB_HOST', '127.0.0.1'),
            mysqlPort: self::positiveInt(
                self::acceptanceEnvironment('DB_PORT', 'DB_PORT', '3306'),
                'MySQL acceptance port',
            ),
            mysqlUsername: $mysqlUsername,
            mysqlPassword: self::acceptanceSecretEnvironment('DB_PASSWORD', 'DB_PASSWORD'),
            redisDriver: self::acceptanceEnvironment('REDIS_DRIVER', 'REDIS_DRIVER', 'auto'),
            redisHost: self::acceptanceEnvironment('REDIS_HOST', 'REDIS_HOST', '127.0.0.1'),
            redisPort: self::positiveInt(
                self::acceptanceEnvironment('REDIS_PORT', 'REDIS_PORT', '6379'),
                'Redis acceptance port',
            ),
            redisPassword: $redisPassword,
            redisDatabase: self::nonNegativeInt(
                self::acceptanceEnvironment('REDIS_DATABASE', 'REDIS_DATABASE', '0'),
                'Redis database',
            ),
            redisTimeoutSeconds: self::positiveFloat(
                self::acceptanceEnvironment('REDIS_TIMEOUT_SECONDS', 'REDIS_TIMEOUT_SECONDS', '2'),
                'Redis timeout',
            ),
            redisReadTimeoutSeconds: self::positiveFloat(
                self::acceptanceEnvironment('REDIS_READ_TIMEOUT_SECONDS', 'REDIS_READ_TIMEOUT_SECONDS', '2'),
                'Redis read timeout',
            ),
            nodeBinary: self::environment('NODE_BINARY', 'node'),
            browserExecutable: self::browserExecutable(),
            commandTimeoutSeconds: self::positiveInt(
                self::environment('VERTOAD_ATTRIBUTION_BROWSER_ACCEPTANCE_TIMEOUT_SECONDS', '120'),
                'Attribution browser command timeout',
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
            'serving_event_visibility_timeout_seconds' => 30,
            'serving_event_retention_seconds' => 600,
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
            throw new \RuntimeException('Unable to connect to the MySQL attribution acceptance control plane.');
        }
    }

    public function redact(string $value): string
    {
        $secrets = array_values(array_filter(
            [$this->mysqlPassword, $this->redisPassword],
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

        $candidates = [];
        foreach (['ProgramFiles', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $variable) {
            $base = getenv($variable);
            if ($base !== false && trim($base) !== '') {
                $candidates[] = rtrim((string) $base, "\\/")
                    . DIRECTORY_SEPARATOR . 'Google' . DIRECTORY_SEPARATOR . 'Chrome'
                    . DIRECTORY_SEPARATOR . 'Application' . DIRECTORY_SEPARATOR . 'chrome.exe';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function acceptanceEnvironment(string $suffix, string $fallback, string $default): string
    {
        $value = self::environment('VERTOAD_ATTRIBUTION_BROWSER_ACCEPTANCE_' . $suffix, '');

        return $value === '' ? self::environment($fallback, $default) : $value;
    }

    private static function acceptanceSecretEnvironment(string $suffix, string $fallback): string
    {
        $value = getenv('VERTOAD_ATTRIBUTION_BROWSER_ACCEPTANCE_' . $suffix);

        return $value === false ? self::secretEnvironment($fallback) : (string) $value;
    }

    private static function secretEnvironment(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : (string) $value;
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

final class AttributionBrowserAcceptanceHarness
{
    private const DATABASE_PATTERN = '/^vertoad_attr_browser_[a-f0-9]{16}$/D';
    private const REDIS_PREFIX_PATTERN = '/^vertoad:acceptance:attribution-browser:[a-f0-9]{16}:$/D';
    private const REDIS_SCAN_PAGE_SIZE = 100;

    private ?PDO $mysqlAdmin = null;
    private ?Connection $connection = null;
    private ?RedisClientInterface $redis = null;
    private bool $databaseCreated = false;
    private bool $redisPrefixOwned = false;
    private bool $cleaned = false;
    /** @var array<string, array{process:string|false,env_exists:bool,env:mixed,server_exists:bool,server:mixed}> */
    private array $environmentBackup = [];
    /** @var array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int}|null */
    private ?array $cleanupEvidence = null;

    private int $advertiserOrganizationId;
    private int $publisherOrganizationId;
    private int $siteId;
    private int $slotId;
    private int $campaignId;
    private string $mysqlVersion = '';

    private function __construct(
        private readonly string $rootPath,
        private readonly string $frontendPath,
        private readonly AttributionBrowserAcceptanceConfig $config,
        private readonly string $runId,
        private readonly string $databaseName,
        private readonly string $redisPrefix,
        private readonly int $port,
        private readonly string $workspace,
        private readonly string $screenshotPath,
        private readonly string $viewerId,
        private readonly string $decisionId,
        private readonly string $priorClickEventId,
        private readonly string $impressionEventId,
        private readonly string $clickEventId,
        private readonly string $conversionEventId,
        #[SensitiveParameter]
        private readonly string $cronToken,
        #[SensitiveParameter]
        private readonly string $reportToken,
        #[SensitiveParameter]
        private readonly string $appKey,
    ) {
    }

    public static function boot(string $rootPath): self
    {
        EnvironmentLoader::load($rootPath);
        $config = AttributionBrowserAcceptanceConfig::fromEnvironment();
        $runId = bin2hex(random_bytes(8));
        $frontendPath = dirname($rootPath) . DIRECTORY_SEPARATOR . 'frontend';
        $workspace = $rootPath . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR
            . 'attribution-browser' . DIRECTORY_SEPARATOR . $runId;
        $harness = new self(
            rootPath: $rootPath,
            frontendPath: $frontendPath,
            config: $config,
            runId: $runId,
            databaseName: 'vertoad_attr_browser_' . $runId,
            redisPrefix: 'vertoad:acceptance:attribution-browser:' . $runId . ':',
            port: self::reserveLoopbackPort(),
            workspace: $workspace,
            screenshotPath: $frontendPath . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
                . '.tmp' . DIRECTORY_SEPARATOR . 'attribution-browser-smoke' . DIRECTORY_SEPARATOR
                . 'conversion-last-click-roi-' . $runId . '.png',
            viewerId: 'viewer-attribution-' . $runId,
            decisionId: 'decision-attribution-' . $runId,
            priorClickEventId: 'click-prior-' . $runId,
            impressionEventId: 'impression-browser-' . $runId,
            clickEventId: 'click-browser-' . $runId,
            conversionEventId: 'conversion-browser-' . $runId,
            cronToken: bin2hex(random_bytes(32)),
            reportToken: bin2hex(random_bytes(32)),
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
        return $this->connection ?? throw new \LogicException('The attribution acceptance connection is unavailable.');
    }

    public function clickEventId(): string
    {
        return $this->clickEventId;
    }

    public function priorClickEventId(): string
    {
        return $this->priorClickEventId;
    }

    public function conversionEventId(): string
    {
        return $this->conversionEventId;
    }

    public function storedConversionEventId(): string
    {
        return 'browser_pixel:public:' . $this->conversionEventId;
    }

    public function decisionId(): string
    {
        return $this->decisionId;
    }

    public function campaignId(): int
    {
        return $this->campaignId;
    }

    public function advertiserOrganizationId(): int
    {
        return $this->advertiserOrganizationId;
    }

    public function publisherOrganizationId(): int
    {
        return $this->publisherOrganizationId;
    }

    public function siteId(): int
    {
        return $this->siteId;
    }

    public function slotId(): int
    {
        return $this->slotId;
    }

    public function screenshotPath(): string
    {
        return $this->screenshotPath;
    }

    /** @return array<string, mixed> */
    public function reportAuthenticationEvidence(): array
    {
        $tokenHash = hash('sha256', $this->reportToken);
        $now = new DateTimeImmutable();
        $stored = $this->connection()->fetchAssociative(
            'SELECT at.access_token_identifier, at.expires_at, at.revoked_at, c.revoked_at AS client_revoked_at '
            . 'FROM oauth_access_tokens at INNER JOIN oauth_clients c ON c.id = at.client_id '
            . 'WHERE at.access_token_identifier = ?',
            [$tokenHash],
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/reports/dashboard?organization_id=' . $this->advertiserOrganizationId)
            ->withHeader('Authorization', 'Bearer ' . $this->reportToken);
        $authenticated = (new BearerTokenAuthenticator(
            new FirstPartySessionRepository($this->connection()),
            new OAuthTokenRepository($this->connection()),
        ))->authenticate($request);
        $context = RequestUserContext::fromRequest($authenticated);

        return [
            'authenticated' => $context->isAuthenticated(),
            'organization_id' => $context->organizationId,
            'scopes' => $context->oauthToken?->scopes ?? [],
            'stored' => is_array($stored),
            'hash_matches' => is_array($stored) && hash_equals($tokenHash, (string) $stored['access_token_identifier']),
            'expires_at' => is_array($stored) ? (string) $stored['expires_at'] : null,
            'now' => $now->format('Y-m-d H:i:s'),
            'revoked' => is_array($stored) && ($stored['revoked_at'] !== null || $stored['client_revoked_at'] !== null),
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
            ++$pages;
            if ($pages > 100_000) {
                throw new \RuntimeException('Attribution acceptance Redis scan exceeded its page limit.');
            }
        } while ($cursor !== '0');

        return $count;
    }

    /** @return array<string, mixed> */
    public function runBrowserScenario(): array
    {
        $tsxCli = $this->frontendPath . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR
            . 'tsx' . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cli.mjs';
        $script = $this->frontendPath . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'attribution-browser-smoke.ts';
        if (!is_file($tsxCli) || !is_file($script)) {
            throw new \RuntimeException('The frontend attribution browser runner dependencies are unavailable.');
        }

        $result = (new ProcessArchiveCommandRunner())->run(
            [$this->config->nodeBinary, $tsxCli, $script],
            null,
            $this->config->commandTimeoutSeconds,
        );
        if ($result->exitCode !== 0) {
            throw new \RuntimeException($this->redact(
                "Attribution browser runner failed with exit code {$result->exitCode}.\n"
                . trim($result->stdout . "\n" . $result->stderr),
            ));
        }

        if (preg_match('/^ATTRIBUTION_BROWSER_EVIDENCE=(\{.*\})$/m', $result->stdout, $matches) !== 1) {
            throw new \RuntimeException($this->redact(
                'Attribution browser runner did not emit parseable evidence. ' . trim($result->stdout),
            ));
        }

        $decoded = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Attribution browser evidence must be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int}
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
        $failure = null;

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
            $this->removeWorkspace();
            $workspaceAfter = is_dir($this->workspace) ? 1 : 0;
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
        ];

        if ($failure !== null) {
            throw new \RuntimeException('Attribution browser acceptance cleanup failed.', previous: $failure);
        }
        if ($redisKeysAfter !== 0 || $databaseAfter !== 0 || $workspaceAfter !== 0) {
            throw new \RuntimeException('Attribution browser acceptance left isolated resources behind.');
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
    }

    private function assertIdentity(): void
    {
        if (preg_match(self::DATABASE_PATTERN, $this->databaseName) !== 1) {
            throw new \LogicException('Unsafe generated attribution acceptance database name.');
        }
        if (preg_match(self::REDIS_PREFIX_PATTERN, $this->redisPrefix) !== 1) {
            throw new \LogicException('Unsafe generated attribution acceptance Redis prefix.');
        }
    }

    private function createWorkspace(): void
    {
        if (file_exists($this->workspace) || is_link($this->workspace)) {
            throw new \RuntimeException('The attribution acceptance workspace already exists.');
        }
        if (!mkdir($this->workspace, 0700, true) && !is_dir($this->workspace)) {
            throw new \RuntimeException('Unable to create the attribution acceptance workspace.');
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
            'DB_PASSWORD' => $this->config->mysqlSettings($this->databaseName)['password'],
            'DB_CHARSET' => 'utf8mb4',
            'REDIS_DRIVER' => $this->config->redisDriver,
            'REDIS_HOST' => $this->config->redisHost,
            'REDIS_PORT' => (string) $this->config->redisPort,
            'REDIS_PASSWORD' => $this->config->redisSettings($this->redisPrefix)['password'],
            'REDIS_DATABASE' => (string) $this->config->redisDatabase,
            'REDIS_PREFIX' => $this->redisPrefix,
            'REDIS_TIMEOUT_SECONDS' => (string) $this->config->redisTimeoutSeconds,
            'REDIS_READ_TIMEOUT_SECONDS' => (string) $this->config->redisReadTimeoutSeconds,
            'REDIS_SERVING_EVENT_VISIBILITY_TIMEOUT_SECONDS' => '30',
            'REDIS_SERVING_EVENT_RETENTION_SECONDS' => '600',
            'CRON_API_TOKEN' => $this->cronToken,
            'CRON_API_ALLOWED_IPS' => '127.0.0.1,::1',
            'CRON_LOCK_TTL_SECONDS' => '30',
            'CRON_EVENT_CONSUME_BATCH_SIZE' => '10',
            'CRON_AGGREGATE_STATISTICS_LOOKBACK_HOURS' => '4',
            'IP_GEO_REPOSITORY' => 'memory',
            'ARCHIVE_WRITER' => 'deterministic',
            'ARCHIVE_COLD_QUERY_RUNNER' => 'fixture',
            'CLOUDFLARE_TRUSTED_PROXIES' => '',
            'TURNSTILE_SECRET_KEY' => '',
            'VERTOAD_ATTRIBUTION_BROWSER_BASE_URL' => $this->publisherBaseUrl(),
            'VERTOAD_ATTRIBUTION_BROWSER_ADVERTISER_ORIGIN' => $this->advertiserOrigin(),
            'VERTOAD_ATTRIBUTION_BROWSER_CRON_TOKEN' => $this->cronToken,
            'VERTOAD_ATTRIBUTION_BROWSER_REPORT_TOKEN' => $this->reportToken,
            'VERTOAD_ATTRIBUTION_BROWSER_VIEWER_ID' => $this->viewerId,
            'VERTOAD_ATTRIBUTION_BROWSER_DECISION_ID' => $this->decisionId,
            'VERTOAD_ATTRIBUTION_BROWSER_PRIOR_CLICK_EVENT_ID' => $this->priorClickEventId,
            'VERTOAD_ATTRIBUTION_BROWSER_IMPRESSION_EVENT_ID' => $this->impressionEventId,
            'VERTOAD_ATTRIBUTION_BROWSER_CLICK_EVENT_ID' => $this->clickEventId,
            'VERTOAD_ATTRIBUTION_BROWSER_CONVERSION_EVENT_ID' => $this->conversionEventId,
            'VERTOAD_ATTRIBUTION_BROWSER_ORGANIZATION_ID' => (string) $this->advertiserOrganizationIdOrZero(),
            'VERTOAD_ATTRIBUTION_BROWSER_CAMPAIGN_ID' => '0',
            'VERTOAD_ATTRIBUTION_BROWSER_SITE_ID' => '0',
            'VERTOAD_ATTRIBUTION_BROWSER_SLOT_ID' => '0',
            'VERTOAD_ATTRIBUTION_BROWSER_SCREENSHOT_PATH' => $this->screenshotPath,
            'VERTOAD_ATTRIBUTION_BROWSER_PHP_BINARY' => PHP_BINARY,
            'VERTOAD_ATTRIBUTION_BROWSER_PHP_BRIDGE' => __DIR__ . DIRECTORY_SEPARATOR . 'attribution-browser-router.php',
            'NO_PROXY' => '127.0.0.1,localhost,.test,publisher.test,advertiser.test',
            'no_proxy' => '127.0.0.1,localhost,.test,publisher.test,advertiser.test',
        ];
        if ($this->config->browserExecutable !== null) {
            $environment['PLAYWRIGHT_CHROMIUM_EXECUTABLE'] = $this->config->browserExecutable;
        }

        foreach ($environment as $name => $value) {
            $this->backupAndSetEnvironment($name, (string) $value);
        }
    }

    private function refreshBrowserIdentityEnvironment(): void
    {
        foreach ([
            'VERTOAD_ATTRIBUTION_BROWSER_ORGANIZATION_ID' => (string) $this->advertiserOrganizationId,
            'VERTOAD_ATTRIBUTION_BROWSER_CAMPAIGN_ID' => (string) $this->campaignId,
            'VERTOAD_ATTRIBUTION_BROWSER_SITE_ID' => (string) $this->siteId,
            'VERTOAD_ATTRIBUTION_BROWSER_SLOT_ID' => (string) $this->slotId,
        ] as $name => $value) {
            if (!array_key_exists($name, $this->environmentBackup)) {
                throw new \LogicException('Acceptance browser identity environment was not initialized.');
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function advertiserOrganizationIdOrZero(): int
    {
        return isset($this->advertiserOrganizationId) ? $this->advertiserOrganizationId : 0;
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
            throw new \RuntimeException('Unable to configure the attribution acceptance environment.');
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
            throw new \RuntimeException('The generated attribution acceptance Redis prefix was not empty.');
        }
        $this->redisPrefixOwned = true;

        $probeKey = $this->redisPrefix . 'harness-probe';
        if (!$this->redis->setEx($probeKey, 'ok', 30) || $this->redis->get($probeKey) !== 'ok') {
            throw new \RuntimeException('The attribution acceptance Redis round trip failed.');
        }
        $this->redis->delete($probeKey);
    }

    private function createDatabase(): void
    {
        $this->mysqlAdmin = $this->config->mysqlAdminPdo();
        $version = (string) $this->mysqlAdmin->query('SELECT VERSION()')->fetchColumn();
        if (preg_match('/^(\d+)\./D', $version, $matches) !== 1 || (int) $matches[1] < 8) {
            throw new \RuntimeException('The attribution browser acceptance runner requires MySQL 8 or newer.');
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
        $this->refreshBrowserIdentityEnvironment();
    }

    private function seed(Connection $connection): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = static fn (DateTimeImmutable $value): string => $value->format('Y-m-d H:i:s');
        $json = static fn (array $value): string => json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $connection->insert('organizations', [
            'name' => 'Attribution browser advertiser',
            'slug' => 'attr-browser-advertiser-' . $this->runId,
        ]);
        $this->advertiserOrganizationId = (int) $connection->lastInsertId();
        $connection->insert('organizations', [
            'name' => 'Attribution browser publisher',
            'slug' => 'attr-browser-publisher-' . $this->runId,
        ]);
        $this->publisherOrganizationId = (int) $connection->lastInsertId();

        $connection->insert('ledger_account_balances', [
            'organization_id' => $this->advertiserOrganizationId,
            'account_type' => 'advertiser_balance',
            'balance_points' => 1_000,
        ]);
        $connection->insert('ledger_account_balances', [
            'organization_id' => $this->publisherOrganizationId,
            'account_type' => 'publisher_earnings',
            'balance_points' => 0,
        ]);

        $connection->insert('sites', [
            'organization_id' => $this->publisherOrganizationId,
            'name' => 'Attribution browser publisher site',
            'domain' => 'publisher.test',
            'status' => 'verified',
            'verified_at' => $date($now->modify('-1 day')),
        ]);
        $this->siteId = (int) $connection->lastInsertId();
        $connection->insert('ad_slots', [
            'site_id' => $this->siteId,
            'name' => 'Attribution browser slot',
            'slot_key' => 'attr-browser-' . $this->runId,
            'width' => 300,
            'height' => 250,
            'status' => 'active',
        ]);
        $this->slotId = (int) $connection->lastInsertId();

        $connection->insert('campaigns', [
            'organization_id' => $this->advertiserOrganizationId,
            'name' => 'Attribution browser campaign',
            'status' => 'active',
            'objective' => 'conversion',
            'pricing_model' => 'cpc',
            'bid_points' => 40,
            'landing_url' => $this->advertiserOrigin() . '/checkout',
            'creative_asset_id' => null,
            'targeting_json' => $json([
                'devices' => [],
                'geos' => [],
                'site_ids' => [$this->siteId],
                'slot_ids' => [$this->slotId],
                'time_windows' => [],
            ]),
            'starts_at' => $date($now->modify('-1 day')),
            'ends_at' => $date($now->modify('+1 day')),
        ]);
        $this->campaignId = (int) $connection->lastInsertId();

        $connection->insert('revenue_share_rules', [
            'scope' => 'global',
            'organization_id' => null,
            'site_id' => null,
            'ad_slot_id' => null,
            'share_ratio_bps' => 5_000,
            'status' => 'active',
            'version' => 1,
            'created_by_user_id' => null,
            'created_at' => $date($now->modify('-1 hour')),
        ]);

        $decision = new AdDecision(
            decisionId: $this->decisionId,
            siteId: $this->siteId,
            slotId: $this->slotId,
            viewerId: $this->viewerId,
            filled: true,
            reason: null,
            iframeHtml: '<iframe title="Attribution acceptance"></iframe>',
            width: 300,
            height: 250,
            adId: 'ad-attribution-' . $this->runId,
            campaignId: $this->campaignId,
            advertiserOrganizationId: $this->advertiserOrganizationId,
            publisherOrganizationId: $this->publisherOrganizationId,
            impressionCostPoints: 0,
            clickCostPoints: 40,
            landingUrl: $this->advertiserOrigin() . '/checkout',
            decidedAt: $now->modify('-30 minutes'),
            requestId: 'req-decision-' . $this->runId,
            ipAddress: '203.0.113.10',
            userAgent: 'VertoAD attribution browser acceptance seed',
            geoCode: 'US-CA',
        );
        (new DatabaseAdDecisionRepository($connection))->save($decision);
        (new DatabaseAdEventRepository($connection))->recordClick(
            $decision,
            $this->priorClickEventId,
            $now->modify('-20 minutes'),
            'req-prior-click-' . $this->runId,
        );

        $connection->insert('oauth_clients', [
            'organization_id' => $this->advertiserOrganizationId,
            'owner_user_id' => null,
            'client_identifier' => 'vocl_attr_browser_' . $this->runId,
            'name' => 'Attribution browser report client',
            'secret_hash' => null,
            'redirect_uris_json' => $json([]),
            'grant_types_json' => $json(['client_credentials']),
            'scopes_json' => $json(['report.read.own']),
            'is_confidential' => 1,
        ]);
        $oauthClientId = (int) $connection->lastInsertId();
        $connection->insert('oauth_access_tokens', [
            'client_id' => $oauthClientId,
            'user_id' => null,
            'organization_id' => $this->advertiserOrganizationId,
            'authorization_code_id' => null,
            'access_token_identifier' => hash('sha256', $this->reportToken),
            'scopes_json' => $json(['report.read.own']),
            'expires_at' => '2099-01-01 00:00:00',
            'revoked_at' => null,
        ]);
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
            throw new \RuntimeException('Attribution acceptance Redis scan returned an invalid response.');
        }

        return [$result[0], $delete ? (int) $result[2] : (int) $result[1]];
    }

    private function deleteRedisKeys(): int
    {
        $deleted = 0;
        for ($pass = 0; $pass < 20; ++$pass) {
            $cursor = '0';
            $pages = 0;
            do {
                [$cursor, $pageDeleted] = $this->scanRedisPage($cursor, true);
                $deleted += $pageDeleted;
                ++$pages;
                if ($pages > 100_000) {
                    throw new \RuntimeException('Attribution acceptance Redis cleanup exceeded its page limit.');
                }
            } while ($cursor !== '0');

            if ($this->redisKeyCount() === 0) {
                return $deleted;
            }
        }

        throw new \RuntimeException('Attribution acceptance Redis cleanup left prefixed keys behind.');
    }

    private function redis(): RedisClientInterface
    {
        return $this->redis ?? throw new \LogicException('The attribution acceptance Redis client is unavailable.');
    }

    private function databaseExists(): int
    {
        if ($this->mysqlAdmin === null) {
            return 0;
        }
        $statement = $this->mysqlAdmin->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :database_name',
        );
        $statement->execute(['database_name' => $this->databaseName]);

        return (int) $statement->fetchColumn();
    }

    private function removeWorkspace(): void
    {
        if (!file_exists($this->workspace) && !is_link($this->workspace)) {
            return;
        }
        $expectedRoot = realpath($this->rootPath . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . 'attribution-browser');
        $workspaceParent = realpath(dirname($this->workspace));
        if ($expectedRoot === false || $workspaceParent === false || $workspaceParent !== $expectedRoot) {
            throw new \LogicException('Refusing to remove an unowned attribution acceptance workspace.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                if (!unlink($path)) {
                    throw new \RuntimeException('Unable to remove an attribution acceptance workspace file.');
                }
            } elseif (!rmdir($path)) {
                throw new \RuntimeException('Unable to remove an attribution acceptance workspace directory.');
            }
        }
        if (!rmdir($this->workspace)) {
            throw new \RuntimeException('Unable to remove the attribution acceptance workspace.');
        }
    }

    private function publisherBaseUrl(): string
    {
        return 'http://publisher.test:' . $this->port;
    }

    private function advertiserOrigin(): string
    {
        return 'http://advertiser.test:' . $this->port;
    }

    private function redact(string $value): string
    {
        return $this->config->redact(str_replace(
            [$this->cronToken, $this->reportToken, $this->appKey],
            '[redacted]',
            $value,
        ));
    }

    /** @return array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int} */
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
        ];
    }

    private static function reserveLoopbackPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) {
            throw new \RuntimeException('Unable to reserve a loopback port for browser acceptance.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($address) || preg_match('/:(\d+)$/D', $address, $matches) !== 1) {
            throw new \RuntimeException('Unable to determine the reserved browser acceptance port.');
        }

        return (int) $matches[1];
    }
}
