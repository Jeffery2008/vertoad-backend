<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance\RedisBacklog;

use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use PDO;
use SensitiveParameter;
use Slim\App;
use Throwable;
use VertoAD\AppFactory;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Install\PhinxMigrationRunner;

final readonly class RedisBacklogAcceptanceConfig
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
    ) {
    }

    public static function fromEnvironment(): self
    {
        $driver = self::environment('DB_DRIVER', 'pdo_mysql');
        if ($driver !== 'pdo_mysql') {
            throw new \RuntimeException('The Redis/MySQL acceptance runner requires DB_DRIVER=pdo_mysql.');
        }

        $mysqlUsername = self::acceptanceEnvironment('DB_USERNAME', 'DB_USERNAME', '');
        if ($mysqlUsername === '') {
            throw new \RuntimeException('A MySQL acceptance username is required.');
        }

        $redisPassword = self::secretEnvironment('REDIS_PASSWORD');
        if ($redisPassword === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for the functional Redis acceptance runner.');
        }

        $mysqlPort = self::positiveInt(
            self::acceptanceEnvironment('DB_PORT', 'DB_PORT', '3306'),
            'MySQL acceptance port',
        );
        $redisPort = self::positiveInt(self::environment('REDIS_PORT', '6379'), 'Redis port');
        $redisDatabase = self::nonNegativeInt(self::environment('REDIS_DATABASE', '0'), 'Redis database');
        $redisTimeout = self::positiveFloat(self::environment('REDIS_TIMEOUT_SECONDS', '2'), 'Redis timeout');
        $redisReadTimeout = self::positiveFloat(
            self::environment('REDIS_READ_TIMEOUT_SECONDS', '2'),
            'Redis read timeout',
        );

        return new self(
            mysqlHost: self::acceptanceEnvironment('DB_HOST', 'DB_HOST', '127.0.0.1'),
            mysqlPort: $mysqlPort,
            mysqlUsername: $mysqlUsername,
            mysqlPassword: self::acceptanceSecretEnvironment('DB_PASSWORD', 'DB_PASSWORD'),
            redisDriver: self::environment('REDIS_DRIVER', 'auto'),
            redisHost: self::environment('REDIS_HOST', '127.0.0.1'),
            redisPort: $redisPort,
            redisPassword: $redisPassword,
            redisDatabase: $redisDatabase,
            redisTimeoutSeconds: $redisTimeout,
            redisReadTimeoutSeconds: $redisReadTimeout,
        );
    }

    /** @return array<string, int|string> */
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

    /** @return array<string, float|int|string> */
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

    /** @return array<string, string> */
    public function runtimeEnvironment(string $database, string $prefix, string $cronToken, string $appKey): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => $appKey,
            'APP_INSTALLED' => 'true',
            'VERTOAD_INSTALL_TEST_BYPASS' => 'true',
            'DB_DRIVER' => 'pdo_mysql',
            'DB_HOST' => $this->mysqlHost,
            'DB_PORT' => (string) $this->mysqlPort,
            'DB_DATABASE' => $database,
            'TEST_DB_DATABASE' => $database,
            'DB_USERNAME' => $this->mysqlUsername,
            'DB_PASSWORD' => $this->mysqlPassword,
            'DB_CHARSET' => 'utf8mb4',
            'REDIS_DRIVER' => $this->redisDriver,
            'REDIS_HOST' => $this->redisHost,
            'REDIS_PORT' => (string) $this->redisPort,
            'REDIS_PASSWORD' => $this->redisPassword,
            'REDIS_DATABASE' => (string) $this->redisDatabase,
            'REDIS_PREFIX' => $prefix,
            'REDIS_TIMEOUT_SECONDS' => (string) $this->redisTimeoutSeconds,
            'REDIS_READ_TIMEOUT_SECONDS' => (string) $this->redisReadTimeoutSeconds,
            'REDIS_SERVING_EVENT_VISIBILITY_TIMEOUT_SECONDS' => '30',
            'REDIS_SERVING_EVENT_RETENTION_SECONDS' => '600',
            'CRON_API_TOKEN' => $cronToken,
            'CRON_API_ALLOWED_IPS' => '127.0.0.1',
            'CRON_EVENT_CONSUME_BATCH_SIZE' => '4',
            'CRON_AGGREGATE_STATISTICS_LOOKBACK_HOURS' => '2',
            'IP_GEO_REPOSITORY' => 'database',
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
            throw new \RuntimeException('Unable to connect to the MySQL acceptance control plane.');
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

    private static function acceptanceEnvironment(string $suffix, string $fallback, string $default): string
    {
        $value = self::environment('VERTOAD_REDIS_MYSQL_ACCEPTANCE_' . $suffix, '');

        return $value === '' ? self::environment($fallback, $default) : $value;
    }

    private static function acceptanceSecretEnvironment(string $suffix, string $fallback): string
    {
        $value = getenv('VERTOAD_REDIS_MYSQL_ACCEPTANCE_' . $suffix);

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

final class RedisBacklogAcceptanceHarness
{
    private const EVENT_LIMIT = 10_000;
    private const REDIS_SCAN_PAGE_SIZE = 100;

    private ?PDO $mysqlAdmin = null;
    private ?RedisClientInterface $redis = null;
    private ?App $app = null;
    private bool $databaseCreated = false;
    private bool $cleaned = false;
    /** @var array<string, array{process: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $environmentBackup = [];
    /** @var array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int}|null */
    private ?array $cleanupEvidence = null;

    private int $advertiserOrganizationId;
    private int $publisherOrganizationId;
    private int $siteId;
    private int $slotId;
    private int $campaignId;

    private function __construct(
        private readonly string $rootPath,
        private readonly RedisBacklogAcceptanceConfig $config,
        private readonly string $runId,
        private readonly string $databaseName,
        private readonly string $redisPrefix,
        #[SensitiveParameter]
        private readonly string $cronToken,
        #[SensitiveParameter]
        private readonly string $appKey,
        private string $mysqlVersion = '',
    ) {
    }

    public static function boot(string $rootPath): self
    {
        EnvironmentLoader::load($rootPath);
        $config = RedisBacklogAcceptanceConfig::fromEnvironment();
        $runId = bin2hex(random_bytes(8));
        $harness = new self(
            rootPath: $rootPath,
            config: $config,
            runId: $runId,
            databaseName: 'vertoad_acceptance_' . $runId,
            redisPrefix: 'vertoad:acceptance:' . $runId . ':',
            cronToken: bin2hex(random_bytes(32)),
            appKey: Key::createNewRandomKey()->saveToAsciiSafeString(),
        );

        try {
            $harness->applyRuntimeEnvironment();
            $harness->connectRedis();
            $harness->createDatabase();
            $harness->migrateAndSeed();
            $harness->app = AppFactory::create($rootPath);

            return $harness;
        } catch (Throwable $exception) {
            try {
                $harness->cleanup();
            } catch (Throwable) {
            }

            throw $exception;
        }
    }

    public function app(): App
    {
        return $this->app ?? throw new \LogicException('The acceptance application is not initialized.');
    }

    public function connection(): Connection
    {
        $container = $this->app()->getContainer();
        if ($container === null) {
            throw new \LogicException('The acceptance application container is unavailable.');
        }

        return $container->get(Connection::class);
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

    public function cronToken(): string
    {
        return $this->cronToken;
    }

    public function redactDiagnostic(string $value): string
    {
        return $this->config->redact(str_replace(
            [$this->cronToken, $this->appKey],
            '[redacted]',
            $value,
        ));
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

    public function campaignId(): int
    {
        return $this->campaignId;
    }

    public function pendingEventCount(): int
    {
        return count($this->redis()->zRangeByScore(
            $this->redisPrefix . 'serving-events:pending',
            '-inf',
            '+inf',
            0,
            self::EVENT_LIMIT,
        ));
    }

    public function requeue(string $eventType, string $eventId): void
    {
        $eventKey = $this->redisPrefix . 'serving-events:event:'
            . hash('sha256', trim($eventType) . ':' . trim($eventId));
        if (!$this->redis()->exists($eventKey)) {
            throw new \RuntimeException('The retained Redis event payload is unavailable for replay.');
        }

        $this->redis()->zAdd($this->redisPrefix . 'serving-events:pending', microtime(true), $eventKey);
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
                throw new \RuntimeException('Redis acceptance key scan exceeded its page limit.');
            }
        } while ($cursor !== '0');

        return $count;
    }

    /**
     * @return array{redis_keys_before:int,redis_keys_deleted:int,redis_keys_after:int,database_before:int,database_after:int}
     */
    public function cleanup(): array
    {
        if ($this->cleaned) {
            return $this->cleanupEvidence ?? [
                'redis_keys_before' => 0,
                'redis_keys_deleted' => 0,
                'redis_keys_after' => 0,
                'database_before' => 0,
                'database_after' => 0,
            ];
        }
        $this->cleaned = true;

        $redisBefore = 0;
        $redisDeleted = 0;
        $redisAfter = 0;
        $databaseBefore = 0;
        $databaseAfter = 0;
        $failure = null;

        try {
            if ($this->redis !== null) {
                $redisBefore = $this->redisKeyCount();
                $redisDeleted = $this->deleteRedisKeys();
                $redisAfter = $this->redisKeyCount();
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            if ($this->app !== null) {
                $this->connection()->close();
                $this->app = null;
            }
            if ($this->mysqlAdmin !== null && $this->databaseCreated) {
                $databaseBefore = $this->databaseExists();
                $this->mysqlAdmin->exec('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
                $databaseAfter = $this->databaseExists();
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;
        } finally {
            $this->restoreRuntimeEnvironment();
        }

        $this->cleanupEvidence = [
            'redis_keys_before' => $redisBefore,
            'redis_keys_deleted' => $redisDeleted,
            'redis_keys_after' => $redisAfter,
            'database_before' => $databaseBefore,
            'database_after' => $databaseAfter,
        ];

        if ($failure !== null) {
            throw new \RuntimeException('Redis/MySQL acceptance cleanup failed.', previous: $failure);
        }

        return $this->cleanupEvidence;
    }

    private function applyRuntimeEnvironment(): void
    {
        foreach ($this->config->runtimeEnvironment(
            $this->databaseName,
            $this->redisPrefix,
            $this->cronToken,
            $this->appKey,
        ) as $name => $value) {
            $this->environmentBackup[$name] = [
                'process' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
            if (!putenv($name . '=' . $value)) {
                throw new \RuntimeException('Unable to configure the isolated acceptance environment.');
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
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
        try {
            $this->redis = RedisClientFactory::fromSettings($this->config->redisSettings($this->redisPrefix));
            $probeKey = $this->redisPrefix . 'harness-probe';
            if (!$this->redis->setEx($probeKey, 'ok', 30) || $this->redis->get($probeKey) !== 'ok') {
                throw new \RuntimeException('Redis acceptance probe failed.');
            }
            $this->redis->delete($probeKey);
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                'Unable to complete the functional Redis acceptance probe: ' . $this->config->redact(
                    $exception::class . ': ' . $exception->getMessage(),
                ),
                previous: $exception,
            );
        }
    }

    private function createDatabase(): void
    {
        if (preg_match('/^vertoad_acceptance_[a-f0-9]{16}$/D', $this->databaseName) !== 1) {
            throw new \LogicException('Unsafe generated acceptance database name.');
        }

        $this->mysqlAdmin = $this->config->mysqlAdminPdo();
        $version = (string) $this->mysqlAdmin->query('SELECT VERSION()')->fetchColumn();
        if (preg_match('/^(\d+)\./D', $version, $matches) !== 1 || (int) $matches[1] < 8) {
            throw new \RuntimeException('The Redis/MySQL acceptance runner requires MySQL 8 or newer.');
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
        $connection = (new ConnectionFactory())->create($settings);

        try {
            $this->seed($connection);
        } finally {
            $connection->close();
        }
    }

    private function seed(Connection $connection): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = static fn (\DateTimeImmutable $value): string => $value->format('Y-m-d H:i:s');
        $json = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $connection->insert('users', [
            'email' => 'redis-acceptance-' . $this->runId . '@example.test',
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'display_name' => 'Redis acceptance operator',
            'status' => 'active',
        ]);
        $userId = (int) $connection->lastInsertId();

        $connection->insert('organizations', [
            'name' => 'Acceptance advertiser',
            'slug' => 'acceptance-advertiser-' . $this->runId,
        ]);
        $this->advertiserOrganizationId = (int) $connection->lastInsertId();
        $connection->insert('organizations', [
            'name' => 'Acceptance publisher',
            'slug' => 'acceptance-publisher-' . $this->runId,
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
            'name' => 'Acceptance publisher site',
            'domain' => $this->runId . '.publisher.example.test',
            'status' => 'verified',
            'verified_at' => $date($now->modify('-1 day')),
        ]);
        $this->siteId = (int) $connection->lastInsertId();
        $connection->insert('ad_slots', [
            'site_id' => $this->siteId,
            'name' => 'Acceptance rectangle',
            'slot_key' => 'acceptance-' . $this->runId,
            'width' => 300,
            'height' => 250,
            'status' => 'active',
        ]);
        $this->slotId = (int) $connection->lastInsertId();

        $objectKey = 'acceptance/' . $this->runId . '/creative.png';
        $connection->insert('asset_upload_intents', [
            'organization_id' => $this->advertiserOrganizationId,
            'uploader_user_id' => $userId,
            'type' => 'image',
            'original_filename' => 'creative.png',
            'object_key' => $objectKey,
            'content_type' => 'image/png',
            'byte_size' => 128,
            'status' => 'confirmed',
            'expires_at' => $date($now->modify('+1 hour')),
        ]);
        $uploadIntentId = (int) $connection->lastInsertId();
        $connection->insert('creative_assets', [
            'upload_intent_id' => $uploadIntentId,
            'organization_id' => $this->advertiserOrganizationId,
            'uploader_user_id' => $userId,
            'type' => 'image',
            'object_key' => $objectKey,
            'content_type' => 'image/png',
            'byte_size' => 128,
            'width' => 300,
            'height' => 250,
            'checksum' => hash('sha256', 'redis-backlog-acceptance'),
            'status' => 'confirmed',
            'snapshot_status' => 'ready',
            'snapshot_png_object_key' => 'acceptance/' . $this->runId . '/snapshot.png',
            'snapshot_webp_object_key' => 'acceptance/' . $this->runId . '/snapshot.webp',
            'thumbnail_webp_object_key' => 'acceptance/' . $this->runId . '/thumbnail.webp',
            'snapshot_completed_at' => $date($now->modify('-30 minutes')),
        ]);
        $assetId = (int) $connection->lastInsertId();
        $connection->insert('creative_reviews', [
            'asset_id' => $assetId,
            'organization_id' => $this->advertiserOrganizationId,
            'status' => 'approved',
            'ai_provider' => 'acceptance-fixture',
            'ai_model' => 'deterministic',
            'ai_risk_score' => '0.0100',
            'ai_risk_labels' => $json([]),
            'ai_reasons' => $json([]),
            'requested_by_user_id' => $userId,
            'final_decision' => 'approved',
            'final_decision_reason' => 'Acceptance fixture',
            'final_decided_by_user_id' => $userId,
            'final_decided_at' => $date($now->modify('-20 minutes')),
        ]);

        $connection->insert('campaigns', [
            'organization_id' => $this->advertiserOrganizationId,
            'name' => 'Redis backlog acceptance campaign',
            'status' => 'active',
            'objective' => 'traffic',
            'pricing_model' => 'cpc',
            'bid_points' => 10,
            'landing_url' => 'https://advertiser.example/acceptance',
            'creative_asset_id' => $assetId,
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
            'created_by_user_id' => $userId,
            'created_at' => $date($now->modify('-1 hour')),
        ]);
    }

    private function redis(): RedisClientInterface
    {
        return $this->redis ?? throw new \LogicException('The acceptance Redis client is not initialized.');
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
            throw new \RuntimeException('Redis acceptance key scan returned an invalid response.');
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
                    throw new \RuntimeException('Redis acceptance cleanup exceeded its page limit.');
                }
            } while ($cursor !== '0');

            if ($this->redisKeyCount() === 0) {
                return $deleted;
            }
        }

        throw new \RuntimeException('Redis acceptance cleanup left prefixed keys behind.');
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
}
