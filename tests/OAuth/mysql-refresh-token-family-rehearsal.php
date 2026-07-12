<?php

declare(strict_types=1);

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthConsentRepository;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthTokenService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

EnvironmentLoader::load(dirname(__DIR__, 2));

if (($argv[1] ?? '') === '--worker') {
    exit(workerMain($argv));
}

exit(main());

function main(): int
{
    $databaseName = 'vertoad_oauth_rehearsal_' . bin2hex(random_bytes(6));
    $server = DriverManager::getConnection(databaseParameters());
    $connection = null;
    $worker = null;
    $workerPipes = [];
    $readyFile = dirname(__DIR__, 2) . '/build/' . $databaseName . '.ready';
    $databaseCreated = false;
    $databaseDropped = false;

    try {
        $server->executeStatement(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $databaseName,
        ));
        $databaseCreated = true;
        runMigrations($databaseName);
        $connection = DriverManager::getConnection(databaseParameters($databaseName));

        $now = new DateTimeImmutable('2026-07-12 10:00:00');
        $client = storeClient($connection);
        $repository = new OAuthTokenRepository($connection);
        $plainRefreshToken = 'vort_mysql_rehearsal_initial';
        $initialAccessId = $repository->createAccessToken(
            $client,
            null,
            null,
            null,
            hash('sha256', 'voat_mysql_rehearsal_initial'),
            ['report.read.own'],
            $now->modify('+15 minutes'),
        );
        $initialRefreshId = $repository->createRefreshToken(
            $initialAccessId,
            $client,
            null,
            hash('sha256', $plainRefreshToken),
            null,
            $now->modify('+30 days'),
        );

        $connection->beginTransaction();
        $candidate = $repository->findUsableRefreshToken(hash('sha256', $plainRefreshToken), $now);
        requireCondition($candidate !== null, 'The initial refresh token was not usable.');

        if (!is_dir(dirname($readyFile)) && !mkdir(dirname($readyFile), 0777, true) && !is_dir(dirname($readyFile))) {
            throw new RuntimeException('Unable to create the MySQL rehearsal coordination directory.');
        }
        [$worker, $workerPipes] = startWorker($databaseName, $plainRefreshToken, $readyFile);
        waitForFile($readyFile, 5.0);
        usleep(500_000);
        requireCondition((bool) (proc_get_status($worker)['running'] ?? false), 'Concurrent rotation was not blocked by the candidate lock.');

        $successorAccessId = $repository->createAccessToken(
            $client,
            null,
            null,
            null,
            hash('sha256', 'voat_mysql_rehearsal_successor'),
            ['report.read.own'],
            $now->modify('+15 minutes'),
        );
        $successorRefreshId = $repository->createRefreshToken(
            $successorAccessId,
            $client,
            null,
            hash('sha256', 'vort_mysql_rehearsal_successor'),
            $initialRefreshId,
            $now->modify('+30 days'),
        );
        requireCondition(
            $repository->rotateRefreshToken($initialRefreshId, $successorRefreshId, $now),
            'The winning refresh rotation could not be committed.',
        );
        $connection->commit();

        $workerResult = finishWorker($worker, $workerPipes, 10.0);
        $worker = null;
        requireCondition($workerResult['exit_code'] === 0, 'The concurrent replay worker failed unexpectedly.');
        requireCondition($workerResult['status'] === 'reuse_rejected', 'The concurrent replay was not rejected.');

        requireCondition(
            (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens') === 2,
            'The losing rotation persisted a refresh token.',
        );
        requireCondition(
            (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens') === 2,
            'The losing rotation persisted an access token.',
        );
        requireCondition(
            (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE revoked_at IS NOT NULL') === 2,
            'Refresh-token family revocation was incomplete.',
        );
        requireCondition(
            (int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_access_tokens WHERE revoked_at IS NOT NULL') === 2,
            'Access-token family revocation was incomplete.',
        );
        requireCondition(
            $connection->fetchOne('SELECT reuse_detected_at FROM oauth_refresh_tokens WHERE id = ?', [$initialRefreshId]) !== null,
            'Refresh-token reuse evidence was not persisted.',
        );

        $beforeUnknown = familyState($connection);
        requireCondition(
            !$repository->markRefreshTokenReuse(hash('sha256', 'vort_unknown_rehearsal'), $now->modify('+1 second')),
            'An unknown refresh token was reported as known.',
        );
        requireCondition($beforeUnknown === familyState($connection), 'An unknown refresh token changed persisted token state.');

        $indexColumns = $connection->fetchFirstColumn(
            "SELECT column_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'oauth_refresh_tokens'
               AND index_name = 'idx_oauth_refresh_tokens_family'
             ORDER BY seq_in_index",
        );
        requireCondition($indexColumns === ['family_identifier'], 'The refresh-token family index is missing or malformed.');
        $familyColumn = $connection->fetchAssociative(
            "SELECT data_type AS data_type,
                    character_maximum_length AS character_maximum_length,
                    is_nullable AS is_nullable
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'oauth_refresh_tokens'
               AND column_name = 'family_identifier'",
        );
        requireCondition(
            is_array($familyColumn)
                && strtolower((string) $familyColumn['data_type']) === 'char'
                && (int) $familyColumn['character_maximum_length'] === 64
                && strtoupper((string) $familyColumn['is_nullable']) === 'NO',
            'The migrated refresh-token family column is malformed.',
        );
        requireCondition(
            (int) $connection->fetchOne('SELECT COUNT(*) FROM phinxlog WHERE version = ?', ['20260607090000']) === 1,
            'The OAuth security migration was not recorded by Phinx.',
        );
        $migrationCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM phinxlog');
        $mysqlVersion = (string) $connection->fetchOne('SELECT VERSION()');

        $connection->close();
        $connection = null;
        $server->executeStatement(sprintf('DROP DATABASE `%s`', $databaseName));
        $databaseDropped = true;
        requireCondition(
            (int) $server->fetchOne(
                'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
                [$databaseName],
            ) === 0,
            'The MySQL rehearsal database cleanup could not be verified.',
        );
        @unlink($readyFile);

        echo json_encode([
            'schema' => 'vertoad.oauth-refresh-family-rehearsal.v1',
            'mysql_version' => $mysqlVersion,
            'rotation_serialized' => true,
            'reuse_detected' => true,
            'refresh_family_revoked' => 2,
            'access_family_revoked' => 2,
            'unknown_token_side_effects' => 0,
            'migration_count' => $migrationCount,
            'family_column' => 'CHAR(64) NOT NULL',
            'family_index' => $indexColumns,
            'cleanup_verified' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        return 0;
    } catch (Throwable $exception) {
        if ($connection instanceof Connection && $connection->isTransactionActive()) {
            $connection->rollBack();
        }
        fwrite(STDERR, 'OAuth MySQL rehearsal failed: ' . $exception->getMessage() . PHP_EOL);

        return 1;
    } finally {
        if (is_resource($worker)) {
            proc_terminate($worker);
            foreach ($workerPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker);
        }
        @unlink($readyFile);
        $connection?->close();
        if ($databaseCreated && !$databaseDropped) {
            $server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $databaseName));
        }
        $server->close();
    }
}

/** @param list<string> $arguments */
function workerMain(array $arguments): int
{
    $databaseName = (string) ($arguments[2] ?? '');
    $plainRefreshToken = (string) ($arguments[3] ?? '');
    $readyFile = (string) ($arguments[4] ?? '');
    if ($databaseName === '' || $plainRefreshToken === '' || $readyFile === '') {
        return 2;
    }

    $connection = DriverManager::getConnection(databaseParameters($databaseName));
    $tokenCounter = 0;
    $service = new OAuthTokenService(
        new OAuthClientRepository($connection),
        new OAuthTokenRepository($connection),
        new OAuthConsentRepository($connection),
        new OAuthClientSecretHasher(),
        static function () use (&$tokenCounter): string {
            ++$tokenCounter;

            return 'mysql-worker-' . $tokenCounter;
        },
    );

    try {
        file_put_contents($readyFile, 'ready', LOCK_EX);
        $service->token([
            'grant_type' => 'refresh_token',
            'client_id' => 'vocl_mysql_family_rehearsal',
            'refresh_token' => $plainRefreshToken,
        ], new DateTimeImmutable('2026-07-12 10:00:01'));
        echo json_encode(['status' => 'unexpected_success'], JSON_THROW_ON_ERROR) . PHP_EOL;

        return 3;
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'already rotated')) {
            echo json_encode(['status' => 'unexpected_runtime_error'], JSON_THROW_ON_ERROR) . PHP_EOL;

            return 4;
        }

        echo json_encode(['status' => 'reuse_rejected'], JSON_THROW_ON_ERROR) . PHP_EOL;

        return 0;
    } catch (Throwable) {
        echo json_encode(['status' => 'database_or_process_error'], JSON_THROW_ON_ERROR) . PHP_EOL;

        return 5;
    } finally {
        $connection->close();
    }
}

/** @return array<string, mixed> */
function databaseParameters(?string $databaseName = null): array
{
    $parameters = [
        'driver' => 'pdo_mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'user' => getenv('DB_USERNAME') ?: 'vertoad',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ];
    if ($databaseName !== null) {
        $parameters['dbname'] = $databaseName;
    }

    return $parameters;
}

function runMigrations(string $databaseName): void
{
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('Unable to read the process environment for Phinx.');
    }
    $environment = array_map(static fn (mixed $value): string => (string) $value, $environment);
    $environment['TEST_DB_DATABASE'] = $databaseName;

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phinx', 'migrate', '-e', 'testing'],
        [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ],
        $pipes,
        dirname(__DIR__, 2),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Phinx for the OAuth MySQL rehearsal.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $diagnostic = trim($output);
        foreach (['DB_PASSWORD', 'REDIS_PASSWORD', 'APP_KEY', 'CRON_API_TOKEN'] as $secretName) {
            $secret = trim((string) ($environment[$secretName] ?? ''));
            if ($secret !== '') {
                $diagnostic = str_replace($secret, '[redacted]', $diagnostic);
            }
        }
        $diagnostic = $diagnostic === ''
            ? 'no child output'
            : substr($diagnostic, -4000);

        throw new RuntimeException(sprintf(
            "Phinx migration failed with exit code %d: %s",
            $exitCode,
            $diagnostic,
        ));
    }
}

function storeClient(Connection $connection): OAuthClient
{
    $connection->insert('oauth_clients', [
        'organization_id' => null,
        'owner_user_id' => null,
        'client_identifier' => 'vocl_mysql_family_rehearsal',
        'name' => 'MySQL Family Rehearsal',
        'secret_hash' => null,
        'redirect_uris_json' => '["https://app.example.com/oauth/callback"]',
        'grant_types_json' => '["refresh_token"]',
        'scopes_json' => '["report.read.own"]',
        'is_confidential' => 0,
        'revoked_at' => null,
    ]);

    return new OAuthClient(
        id: (int) $connection->lastInsertId(),
        organizationId: null,
        ownerUserId: null,
        clientIdentifier: 'vocl_mysql_family_rehearsal',
        name: 'MySQL Family Rehearsal',
        secretHash: null,
        redirectUris: ['https://app.example.com/oauth/callback'],
        grantTypes: ['refresh_token'],
        scopes: ['report.read.own'],
        isConfidential: false,
        revokedAt: null,
    );
}

/** @return array{0:resource, 1:array<int, resource>} */
function startWorker(string $databaseName, string $plainRefreshToken, string $readyFile): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--worker', $databaseName, $plainRefreshToken, $readyFile],
        [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the concurrent MySQL rotation worker.');
    }

    return [$process, $pipes];
}

/** @param resource $process @param array<int, resource> $pipes @return array{exit_code:int, status:string} */
function finishWorker($process, array $pipes, float $timeoutSeconds): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $status = proc_get_status($process);
        if (!(bool) ($status['running'] ?? false)) {
            break;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    $status = proc_get_status($process);
    if ((bool) ($status['running'] ?? false)) {
        proc_terminate($process);
        throw new RuntimeException('The concurrent MySQL rotation worker timed out.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = (int) ($status['exitcode'] ?? -1);
    $closeExitCode = proc_close($process);
    if ($exitCode < 0) {
        $exitCode = $closeExitCode;
    }
    $decoded = json_decode(trim((string) $stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The MySQL rotation worker returned invalid evidence: ' . trim((string) $stderr));
    }

    return ['exit_code' => $exitCode, 'status' => (string) ($decoded['status'] ?? '')];
}

function waitForFile(string $path, float $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    while (!is_file($path) && microtime(true) < $deadline) {
        usleep(20_000);
    }
    requireCondition(is_file($path), 'The concurrent MySQL rotation worker did not become ready.');
}

/** @return array{refresh_tokens:list<array<string, mixed>>, access_tokens:list<array<string, mixed>>} */
function familyState(Connection $connection): array
{
    return [
        'refresh_tokens' => $connection->fetchAllAssociative(
            'SELECT id, access_token_id, family_identifier, revoked_at, rotated_at, reuse_detected_at
             FROM oauth_refresh_tokens
             ORDER BY id',
        ),
        'access_tokens' => $connection->fetchAllAssociative(
            'SELECT id, revoked_at FROM oauth_access_tokens ORDER BY id',
        ),
    ];
}

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
