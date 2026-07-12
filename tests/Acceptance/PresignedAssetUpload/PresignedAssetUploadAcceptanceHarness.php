<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance\PresignedAssetUpload;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Defuse\Crypto\Key;
use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use GdImage;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;
use VertoAD\AppFactory;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Infrastructure\Storage\AwsS3PresignedUploadSigner;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\S3AssetObjectStorage;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Service\Assets\AssetObjectStorageInterface;

final readonly class PresignedAssetUploadAcceptanceConfig
{
    private const ENVIRONMENT_PREFIX = 'VERTOAD_PRESIGNED_ASSET_UPLOAD_ACCEPTANCE_';

    private function __construct(
        public string $mysqlHost,
        public int $mysqlPort,
        public string $mysqlUsername,
        #[SensitiveParameter]
        private string $mysqlPassword,
        public string $s3Endpoint,
        public string $s3Region,
        public string $s3Bucket,
        #[SensitiveParameter]
        private string $s3AccessKeyId,
        #[SensitiveParameter]
        private string $s3SecretAccessKey,
        public bool $s3PathStyleEndpoint,
        public ?string $s3ServerSideEncryption,
        public int $s3MaxReadBytes,
    ) {
    }

    public static function fromEnvironment(): self
    {
        if (self::value('DB_DRIVER', 'DB_DRIVER', 'pdo_mysql') !== 'pdo_mysql') {
            throw new \RuntimeException('Presigned asset acceptance requires DB_DRIVER=pdo_mysql.');
        }

        $mysqlUsername = self::value('DB_USERNAME', 'DB_USERNAME', '');
        if ($mysqlUsername === '') {
            throw new \RuntimeException('A MySQL acceptance username is required.');
        }

        $endpoint = rtrim(self::value('S3_ENDPOINT', 'S3_ENDPOINT', ''), '/');
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''))) : '';
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || !str_ends_with($host, '.r2.cloudflarestorage.com')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('A credential-free Cloudflare R2 HTTPS endpoint is required.');
        }

        $bucket = self::value('S3_BUCKET', 'S3_BUCKET', '');
        $accessKeyId = self::secret('S3_ACCESS_KEY_ID', 'S3_ACCESS_KEY_ID');
        $secretAccessKey = self::secret('S3_SECRET_ACCESS_KEY', 'S3_SECRET_ACCESS_KEY');
        if ($bucket === '' || $accessKeyId === '' || $secretAccessKey === '') {
            throw new \RuntimeException('Cloudflare R2 bucket credentials are required.');
        }

        $pathStyle = filter_var(
            self::value('S3_PATH_STYLE_ENDPOINT', 'S3_PATH_STYLE_ENDPOINT', 'true'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if ($pathStyle === null) {
            throw new \RuntimeException('S3_PATH_STYLE_ENDPOINT must be a boolean value.');
        }

        $encryption = self::value(
            'S3_SERVER_SIDE_ENCRYPTION',
            'ASSET_S3_SERVER_SIDE_ENCRYPTION',
            '',
        );
        if ($encryption !== '' && !in_array($encryption, ['AES256', 'aws:kms'], true)) {
            throw new \RuntimeException('Asset S3 server-side encryption is invalid.');
        }

        return new self(
            mysqlHost: self::value('DB_HOST', 'DB_HOST', '127.0.0.1'),
            mysqlPort: self::positiveInt(self::value('DB_PORT', 'DB_PORT', '3306'), 'MySQL acceptance port'),
            mysqlUsername: $mysqlUsername,
            mysqlPassword: self::secret('DB_PASSWORD', 'DB_PASSWORD'),
            s3Endpoint: $endpoint,
            s3Region: self::value('S3_REGION', 'S3_REGION', 'auto'),
            s3Bucket: $bucket,
            s3AccessKeyId: $accessKeyId,
            s3SecretAccessKey: $secretAccessKey,
            s3PathStyleEndpoint: $pathStyle,
            s3ServerSideEncryption: $encryption === '' ? null : $encryption,
            s3MaxReadBytes: self::positiveInt(
                self::value('S3_MAX_READ_BYTES', 'ASSET_S3_MAX_READ_BYTES', '262144000'),
                'Asset S3 read limit',
            ),
        );
    }

    /** @return array<string, mixed> */
    public function mysqlAdminParameters(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => $this->mysqlHost,
            'port' => $this->mysqlPort,
            'user' => $this->mysqlUsername,
            'password' => $this->mysqlPassword,
            'charset' => 'utf8mb4',
        ];
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
    public function s3Settings(): array
    {
        return [
            'endpoint' => $this->s3Endpoint,
            'region' => $this->s3Region,
            'bucket' => $this->s3Bucket,
            'access_key_id' => $this->s3AccessKeyId,
            'secret_access_key' => $this->s3SecretAccessKey,
            'path_style_endpoint' => $this->s3PathStyleEndpoint,
            'server_side_encryption' => $this->s3ServerSideEncryption ?? '',
            'max_read_bytes' => $this->s3MaxReadBytes,
            'snapshot_cache_control' => 'public, max-age=31536000, immutable',
        ];
    }

    /** @return array<string, mixed> */
    public function s3ClientSettings(): array
    {
        return [
            'version' => 'latest',
            'region' => $this->s3Region === '' ? 'auto' : $this->s3Region,
            'endpoint' => $this->s3Endpoint,
            'use_path_style_endpoint' => $this->s3PathStyleEndpoint,
            'credentials' => new Credentials($this->s3AccessKeyId, $this->s3SecretAccessKey),
        ];
    }

    /** @return array<string, string> */
    public function runtimeEnvironment(string $database, #[SensitiveParameter] string $appKey): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
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
            'S3_ENDPOINT' => $this->s3Endpoint,
            'S3_REGION' => $this->s3Region,
            'S3_BUCKET' => $this->s3Bucket,
            'S3_ACCESS_KEY_ID' => $this->s3AccessKeyId,
            'S3_SECRET_ACCESS_KEY' => $this->s3SecretAccessKey,
            'S3_PATH_STYLE_ENDPOINT' => $this->s3PathStyleEndpoint ? 'true' : 'false',
            'ASSET_S3_SERVER_SIDE_ENCRYPTION' => $this->s3ServerSideEncryption ?? '',
            'ASSET_S3_MAX_READ_BYTES' => (string) $this->s3MaxReadBytes,
            'R2_PUBLIC_BASE_URL' => 'https://assets.acceptance.invalid',
            'IP_GEO_REPOSITORY' => 'memory',
        ];
    }

    public function endpointHost(): string
    {
        return strtolower((string) (parse_url($this->s3Endpoint, PHP_URL_HOST) ?: ''));
    }

    public function safeMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        $message = str_replace(
            array_values(array_filter([
                $this->mysqlPassword,
                $this->s3AccessKeyId,
                $this->s3SecretAccessKey,
            ], static fn (string $secret): bool => $secret !== '')),
            '[redacted]',
            $message,
        );
        $message = preg_replace(
            '~https://[^\s?]+\?[^\s]+~i',
            '[redacted-presigned-url]',
            $message,
        ) ?? 'Acceptance operation failed.';
        $message = preg_replace(
            '/([?&](?:X-Amz-(?:Credential|Signature|Security-Token)|AWSAccessKeyId)=)[^&\s]+/i',
            '$1[redacted]',
            $message,
        ) ?? 'Acceptance operation failed.';

        return $message === '' ? 'Acceptance operation failed.' : $message;
    }

    private static function value(string $suffix, string $fallback, string $default): string
    {
        $value = getenv(self::ENVIRONMENT_PREFIX . $suffix);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }
        $fallbackValue = getenv($fallback);

        return $fallbackValue === false ? $default : trim((string) $fallbackValue);
    }

    private static function secret(string $suffix, string $fallback): string
    {
        $value = getenv(self::ENVIRONMENT_PREFIX . $suffix);
        if ($value !== false) {
            return (string) $value;
        }
        $fallbackValue = getenv($fallback);

        return $fallbackValue === false ? '' : (string) $fallbackValue;
    }

    private static function positiveInt(string $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new \RuntimeException($label . ' must be a positive integer.');
        }

        return (int) $value;
    }
}

final class PresignedAssetUploadAcceptanceHarness
{
    private const DATABASE_PATTERN = '/^vertoad_asset_acceptance_[a-f0-9]{16}$/D';
    private const RUN_ID_PATTERN = '/^[a-f0-9]{16}$/D';

    private ?Connection $mysqlAdmin = null;
    private ?Connection $connection = null;
    private ?S3Client $s3Client = null;
    private ?App $app = null;
    private bool $databaseCreated = false;
    private bool $objectPrefixOwned = false;
    private bool $cleaned = false;

    /** @var array<string, array{process:string|false,env_exists:bool,env:mixed,server_exists:bool,server:mixed}> */
    private array $environmentBackup = [];

    /** @var array{objects_before:int,objects_deleted:int,objects_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int}|null */
    private ?array $cleanupEvidence = null;

    private function __construct(
        private readonly string $rootPath,
        private readonly PresignedAssetUploadAcceptanceConfig $config,
        private readonly string $runId,
        private readonly string $databaseName,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $objectPrefix,
        private readonly string $workspace,
        #[SensitiveParameter]
        private readonly string $sessionToken,
        #[SensitiveParameter]
        private readonly string $appKey,
        private string $mysqlVersion = '',
    ) {
    }

    public static function boot(string $rootPath): self
    {
        EnvironmentLoader::load($rootPath);
        $config = PresignedAssetUploadAcceptanceConfig::fromEnvironment();
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('Presigned asset acceptance requires a 64-bit PHP runtime.');
        }

        $runId = bin2hex(random_bytes(8));
        $organizationId = 1_000_000_000_000 + (int) hexdec(substr($runId, 0, 13));
        $userId = 1_000_000_000_000 + (int) hexdec(substr(strrev($runId), 0, 13));
        $harness = new self(
            rootPath: rtrim($rootPath, "\\/"),
            config: $config,
            runId: $runId,
            databaseName: 'vertoad_asset_acceptance_' . $runId,
            organizationId: $organizationId,
            userId: $userId,
            objectPrefix: 'organizations/' . $organizationId . '/assets/',
            workspace: rtrim($rootPath, "\\/") . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR
                . 'presigned-asset-upload' . DIRECTORY_SEPARATOR . $runId,
            sessionToken: bin2hex(random_bytes(32)),
            appKey: Key::createNewRandomKey()->saveToAsciiSafeString(),
        );

        try {
            $harness->initialize();

            return $harness;
        } catch (Throwable $exception) {
            $message = $config->safeMessage($exception);
            try {
                $harness->cleanup();
            } catch (Throwable $cleanupException) {
                $message .= ' Cleanup: ' . $config->safeMessage($cleanupException);
            }

            throw new \RuntimeException('Presigned asset acceptance initialization failed: ' . $message);
        }
    }

    public function app(): App
    {
        return $this->app ?? throw new \LogicException('The acceptance application is unavailable.');
    }

    public function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('The acceptance database connection is unavailable.');
    }

    public function mysqlVersion(): string
    {
        return $this->mysqlVersion;
    }

    public function migrationCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM phinxlog');
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function objectPrefix(): string
    {
        return $this->objectPrefix;
    }

    /** @return array{path:string,byte_size:int,checksum:string,width:int,height:int,content_type:string} */
    public function createImageFixture(
        string $name,
        string $contentType,
        int $width,
        int $height,
    ): array {
        if (
            preg_match('/^[a-z0-9][a-z0-9._-]{0,80}$/D', $name) !== 1
            || !in_array($contentType, ['image/png', 'image/jpeg'], true)
            || $width <= 0
            || $height <= 0
        ) {
            throw new \InvalidArgumentException('Invalid acceptance image fixture specification.');
        }

        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            throw new \RuntimeException('Unable to allocate an acceptance image fixture.');
        }
        $background = imagecolorallocate($image, 15, 118, 110);
        $foreground = imagecolorallocate($image, 249, 115, 22);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        imageline($image, 0, 0, $width - 1, $height - 1, $foreground);

        ob_start();
        try {
            $encoded = $contentType === 'image/png'
                ? imagepng($image, null, 6)
                : imagejpeg($image, null, 91);
            $body = ob_get_clean();
        } finally {
            imagedestroy($image);
            if (ob_get_level() > 0 && !isset($body)) {
                ob_end_clean();
            }
        }
        if (!$encoded || !is_string($body) || $body === '') {
            throw new \RuntimeException('Unable to encode an acceptance image fixture.');
        }

        $path = $this->workspace . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
            throw new \RuntimeException('Unable to persist an acceptance image fixture.');
        }

        return [
            'path' => $path,
            'byte_size' => strlen($body),
            'checksum' => 'sha256:' . hash('sha256', $body),
            'width' => $width,
            'height' => $height,
            'content_type' => $contentType,
        ];
    }

    /**
     * @param array<string, mixed> $upload
     * @return array{status:int,declared_byte_size:int,signed_header_count:int,algorithm:string,content_type:string}
     */
    public function uploadPresigned(
        array $upload,
        string $objectKey,
        string $fixturePath,
        ?string $contentTypeOverride = null,
    ): array
    {
        $method = strtoupper(trim((string) ($upload['method'] ?? '')));
        $url = trim((string) ($upload['url'] ?? ''));
        $objectKey = trim($objectKey);
        $headers = $upload['headers'] ?? null;
        if ($method !== 'PUT' || $url === '' || !is_array($headers)) {
            throw new \RuntimeException('The application returned an invalid presigned upload contract.');
        }
        $this->assertOwnedObjectKey($objectKey);
        $this->assertFixturePath($fixturePath);

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $endpointHost = $this->config->endpointHost();
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || ($host !== $endpointHost && !str_ends_with($host, '.' . $endpointHost))
            || isset($parts['user'])
            || isset($parts['pass'])
            || !isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('The application returned a presigned URL outside the configured R2 endpoint.');
        }

        parse_str((string) $parts['query'], $query);
        $query = array_change_key_case(is_array($query) ? $query : [], CASE_LOWER);
        $algorithm = trim((string) ($query['x-amz-algorithm'] ?? ''));
        $signature = trim((string) ($query['x-amz-signature'] ?? ''));
        $credential = trim((string) ($query['x-amz-credential'] ?? ''));
        $expires = trim((string) ($query['x-amz-expires'] ?? ''));
        $signedHeaders = strtolower(trim((string) ($query['x-amz-signedheaders'] ?? '')));
        $signedHeaderList = array_values(array_filter(explode(';', $signedHeaders)));
        foreach (['host', 'x-amz-meta-vertoad-byte-size'] as $requiredHeader) {
            if (!in_array($requiredHeader, $signedHeaderList, true)) {
                throw new \RuntimeException('The presigned upload omitted a required signed header.');
            }
        }
        if ($this->config->s3ServerSideEncryption !== null
            && !in_array('x-amz-server-side-encryption', $signedHeaderList, true)) {
            throw new \RuntimeException('The presigned upload omitted the configured encryption header.');
        }
        if (
            $algorithm !== 'AWS4-HMAC-SHA256'
            || preg_match('/^[a-f0-9]{64}$/Di', $signature) !== 1
            || $credential === ''
            || !ctype_digit($expires)
            || (int) $expires <= 0
        ) {
            throw new \RuntimeException('The application returned an invalid AWS Signature V4 contract.');
        }

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_numeric($value))) {
                throw new \RuntimeException('The application returned an invalid presigned upload header.');
            }
            $requestHeaders[$name] = (string) $value;
        }
        $returnedContentType = strtolower(trim(explode(
            ';',
            (string) ($requestHeaders['Content-Type'] ?? ''),
            2,
        )[0]));
        if ($returnedContentType === '') {
            throw new \RuntimeException('The presigned upload omitted its Content-Type request header.');
        }
        if ($contentTypeOverride !== null) {
            if (!in_array($contentTypeOverride, ['image/png', 'image/jpeg'], true)) {
                throw new \InvalidArgumentException('Unsupported acceptance MIME override.');
            }
            $requestHeaders['Content-Type'] = $contentTypeOverride;
        }
        $sentContentType = $contentTypeOverride ?? $returnedContentType;
        $declaredByteSize = trim((string) ($requestHeaders['x-amz-meta-vertoad-byte-size'] ?? ''));
        if (!ctype_digit($declaredByteSize) || (int) $declaredByteSize <= 0) {
            throw new \RuntimeException('The presigned upload omitted its declared object size metadata.');
        }

        $stream = fopen($fixturePath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open the acceptance upload fixture.');
        }
        try {
            $response = (new Client())->request('PUT', $url, [
                'headers' => $requestHeaders,
                'body' => $stream,
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => 10.0,
                'timeout' => 60.0,
            ]);
        } catch (Throwable) {
            throw new \RuntimeException('The real presigned R2 PUT transport failed.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('The real presigned R2 PUT returned HTTP ' . $status . '.');
        }

        $this->waitForObject($objectKey);

        return [
            'status' => $status,
            'declared_byte_size' => (int) $declaredByteSize,
            'signed_header_count' => count($signedHeaderList),
            'algorithm' => $algorithm,
            'content_type' => $sentContentType,
        ];
    }

    /** @return array{content_type:string,byte_size:int,declared_byte_size:int,server_side_encryption:?string} */
    public function objectMetadata(string $objectKey): array
    {
        $this->assertOwnedObjectKey($objectKey);
        try {
            $head = $this->s3()->headObject([
                'Bucket' => $this->config->s3Bucket,
                'Key' => $objectKey,
            ]);
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                'Unable to inspect the real R2 acceptance object: ' . $this->config->safeMessage($exception),
            );
        }
        $metadata = is_array($head['Metadata'] ?? null)
            ? array_change_key_case($head['Metadata'], CASE_LOWER)
            : [];

        return [
            'content_type' => strtolower(trim(explode(';', (string) ($head['ContentType'] ?? ''), 2)[0])),
            'byte_size' => (int) ($head['ContentLength'] ?? -1),
            'declared_byte_size' => (int) ($metadata['vertoad-byte-size'] ?? -1),
            'server_side_encryption' => isset($head['ServerSideEncryption'])
                ? trim((string) $head['ServerSideEncryption'])
                : null,
        ];
    }

    public function objectChecksum(string $objectKey): string
    {
        $this->assertOwnedObjectKey($objectKey);
        try {
            $result = $this->s3()->getObject([
                'Bucket' => $this->config->s3Bucket,
                'Key' => $objectKey,
            ]);
            $body = $result['Body'] ?? null;
            $bytes = $body instanceof StreamInterface ? $body->getContents() : $body;
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                'Unable to read the real R2 acceptance object: ' . $this->config->safeMessage($exception),
            );
        }
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The real R2 acceptance object returned no bytes.');
        }

        return 'sha256:' . hash('sha256', $bytes);
    }

    /** @return list<string> */
    public function objectKeys(): array
    {
        return $this->listObjectKeys();
    }

    /** @return list<string> */
    public function workspaceEntries(): array
    {
        $entries = glob($this->workspace . DIRECTORY_SEPARATOR . '*') ?: [];
        sort($entries);

        return array_values($entries);
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $headers */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
    ): ResponseInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Authorization', 'Bearer ' . $this->sessionToken)
            ->withHeader('User-Agent', 'VertoAD presigned asset acceptance');
        if ($body !== null) {
            $request = $request
                ->withParsedBody($body)
                ->withHeader('Content-Type', 'application/json');
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->handle($request);
    }

    /**
     * @return array{objects_before:int,objects_deleted:int,objects_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int}
     */
    public function cleanup(): array
    {
        if ($this->cleaned) {
            return $this->cleanupEvidence ?? $this->emptyCleanupEvidence();
        }
        $this->cleaned = true;

        $objectsBefore = 0;
        $objectsDeleted = 0;
        $objectsAfter = 0;
        $databaseBefore = 0;
        $databaseAfter = 0;
        $workspaceBefore = is_dir($this->workspace) ? 1 : 0;
        $workspaceAfter = $workspaceBefore;
        $failures = [];

        try {
            if ($this->objectPrefixOwned && $this->s3Client !== null) {
                $keys = $this->listObjectKeys();
                $objectsBefore = count($keys);
                foreach ($keys as $key) {
                    $this->s3Client->deleteObject([
                        'Bucket' => $this->config->s3Bucket,
                        'Key' => $key,
                    ]);
                    ++$objectsDeleted;
                }
                for ($attempt = 0; $attempt < 20; ++$attempt) {
                    $remaining = $this->listObjectKeys();
                    if ($remaining === []) {
                        break;
                    }
                    usleep(100_000);
                }
                $objectsAfter = count($this->listObjectKeys());
            }
        } catch (Throwable $exception) {
            $failures[] = 'R2 cleanup: ' . $this->config->safeMessage($exception);
        }

        try {
            $container = $this->app?->getContainer();
            if ($container instanceof Container && $container->has(Connection::class)) {
                $container->get(Connection::class)->close();
            }
            $this->connection?->close();
            $this->connection = null;
            if ($this->mysqlAdmin !== null && $this->databaseCreated) {
                $databaseBefore = $this->databaseExists();
                $this->mysqlAdmin->executeStatement('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
                $databaseAfter = $this->databaseExists();
            }
            $this->mysqlAdmin?->close();
            $this->mysqlAdmin = null;
        } catch (Throwable $exception) {
            $failures[] = 'MySQL cleanup: ' . $this->config->safeMessage($exception);
        }

        try {
            $this->removeWorkspace();
            $workspaceAfter = is_dir($this->workspace) ? 1 : 0;
        } catch (Throwable $exception) {
            $failures[] = 'workspace cleanup: ' . $this->config->safeMessage($exception);
        } finally {
            $this->restoreRuntimeEnvironment();
        }

        $this->cleanupEvidence = [
            'objects_before' => $objectsBefore,
            'objects_deleted' => $objectsDeleted,
            'objects_after' => $objectsAfter,
            'database_before' => $databaseBefore,
            'database_after' => $databaseAfter,
            'workspace_before' => $workspaceBefore,
            'workspace_after' => $workspaceAfter,
        ];
        if ($objectsAfter !== 0 || $databaseAfter !== 0 || $workspaceAfter !== 0) {
            $failures[] = 'isolated resources remained after cleanup';
        }
        if ($failures !== []) {
            throw new \RuntimeException('Presigned asset acceptance cleanup failed: ' . implode('; ', $failures));
        }

        return $this->cleanupEvidence;
    }

    private function initialize(): void
    {
        $this->assertIdentity();
        $this->applyRuntimeEnvironment();
        $this->createWorkspace();
        $this->connectR2();
        $this->createDatabase();
        $this->migrateAndSeed();
        $this->createApplication();
    }

    private function applyRuntimeEnvironment(): void
    {
        foreach ($this->config->runtimeEnvironment($this->databaseName, $this->appKey) as $name => $value) {
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

    private function createWorkspace(): void
    {
        if (file_exists($this->workspace) || is_link($this->workspace)) {
            throw new \RuntimeException('The acceptance workspace unexpectedly already exists.');
        }
        if (!mkdir($this->workspace, 0700, true) && !is_dir($this->workspace)) {
            throw new \RuntimeException('Unable to create the acceptance workspace.');
        }
    }

    private function connectR2(): void
    {
        try {
            $this->s3Client = new S3Client($this->config->s3ClientSettings());
            if ($this->listObjectKeys() !== []) {
                throw new \RuntimeException('The generated R2 acceptance prefix is not empty.');
            }
            $this->objectPrefixOwned = true;
        } catch (Throwable $exception) {
            throw new \RuntimeException('Unable to initialize real R2 acceptance storage: ' . $this->config->safeMessage($exception));
        }
    }

    private function createDatabase(): void
    {
        try {
            $this->mysqlAdmin = DriverManager::getConnection($this->config->mysqlAdminParameters());
            $version = (string) $this->mysqlAdmin->fetchOne('SELECT VERSION()');
            if (preg_match('/^(\d+)\./D', $version, $matches) !== 1 || (int) $matches[1] < 8) {
                throw new \RuntimeException('Presigned asset acceptance requires MySQL 8 or newer.');
            }
            $this->mysqlVersion = $version;
            if ($this->databaseExists() !== 0) {
                throw new \RuntimeException('The generated acceptance database unexpectedly already exists.');
            }
            $this->mysqlAdmin->executeStatement(
                'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            );
            $this->databaseCreated = true;
        } catch (Throwable $exception) {
            throw new \RuntimeException('Unable to create the isolated MySQL acceptance database: ' . $this->config->safeMessage($exception));
        }
    }

    private function migrateAndSeed(): void
    {
        $settings = $this->config->mysqlSettings($this->databaseName);
        (new PhinxMigrationRunner($this->rootPath))->migrate($settings);
        $this->connection = (new ConnectionFactory())->create($settings);
        $now = new \DateTimeImmutable();
        $this->connection->insert('users', [
            'id' => $this->userId,
            'email' => 'asset-acceptance-' . $this->runId . '@example.invalid',
            'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
            'display_name' => 'Asset Acceptance',
            'status' => 'active',
            'email_verified_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->connection->insert('organizations', [
            'id' => $this->organizationId,
            'name' => 'Asset Acceptance ' . $this->runId,
            'slug' => 'asset-acceptance-' . $this->runId,
            'billing_status' => 'active',
        ]);
        $this->connection->insert('organization_members', [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'status' => 'active',
            'title' => 'Owner',
        ]);
        $this->connection->insert('first_party_sessions', [
            'user_id' => $this->userId,
            'session_token_hash' => hash('sha256', $this->sessionToken),
            'expires_at' => $now->modify('+2 hours')->format('Y-m-d H:i:s'),
        ]);
    }

    private function createApplication(): void
    {
        $this->app = AppFactory::create($this->rootPath);
        $container = $this->app->getContainer();
        if (!$container instanceof Container) {
            throw new \RuntimeException('The production application container is unavailable.');
        }
        $settings = $this->config->s3Settings();
        $signer = new AwsS3PresignedUploadSigner($settings);
        $storage = new S3AssetObjectStorage($settings);
        $container->set(ObjectStorageUploadSignerInterface::class, $signer);
        $container->set(ObjectStorageInspectorInterface::class, $storage);
        $container->set(AssetObjectStorageInterface::class, $storage);
    }

    /** @return list<string> */
    private function listObjectKeys(): array
    {
        if ($this->s3Client === null) {
            return [];
        }
        $keys = [];
        $continuationToken = null;
        do {
            $request = [
                'Bucket' => $this->config->s3Bucket,
                'Prefix' => $this->objectPrefix,
                'MaxKeys' => 1000,
            ];
            if ($continuationToken !== null) {
                $request['ContinuationToken'] = $continuationToken;
            }
            $result = $this->s3Client->listObjectsV2($request);
            foreach ($result['Contents'] ?? [] as $object) {
                $key = trim((string) ($object['Key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $this->assertOwnedObjectKey($key);
                $keys[] = $key;
            }
            $continuationToken = ($result['IsTruncated'] ?? false)
                ? trim((string) ($result['NextContinuationToken'] ?? ''))
                : null;
            if ($continuationToken === '') {
                throw new \RuntimeException('R2 returned a truncated listing without a continuation token.');
            }
        } while ($continuationToken !== null);
        sort($keys);

        return array_values(array_unique($keys));
    }

    private function waitForObject(string $objectKey, ?string $contentType = null): void
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            try {
                $metadata = $this->objectMetadata($objectKey);
                if ($contentType === null || $metadata['content_type'] === $contentType) {
                    return;
                }
            } catch (Throwable) {
            }
            usleep(100_000);
        }

        throw new \RuntimeException('The real R2 object did not become readable with the expected metadata.');
    }

    private function s3(): S3Client
    {
        return $this->s3Client ?? throw new \LogicException('The acceptance R2 client is unavailable.');
    }

    private function databaseExists(): int
    {
        if ($this->mysqlAdmin === null) {
            return 0;
        }

        return (int) $this->mysqlAdmin->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$this->databaseName],
        );
    }

    private function assertIdentity(): void
    {
        $workspaceParent = $this->rootPath . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR
            . 'presigned-asset-upload';
        if (
            preg_match(self::RUN_ID_PATTERN, $this->runId) !== 1
            || preg_match(self::DATABASE_PATTERN, $this->databaseName) !== 1
            || $this->organizationId <= 0
            || $this->userId <= 0
            || $this->objectPrefix !== 'organizations/' . $this->organizationId . '/assets/'
            || dirname($this->workspace) !== $workspaceParent
            || basename($this->workspace) !== $this->runId
        ) {
            throw new \LogicException('Unsafe presigned asset acceptance resource identity.');
        }
    }

    private function assertOwnedObjectKey(string $objectKey): void
    {
        $suffix = substr($objectKey, strlen($this->objectPrefix));
        if (
            !str_starts_with($objectKey, $this->objectPrefix)
            || $suffix === ''
            || str_contains($suffix, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $suffix) === 1
            || preg_match(
                '~^(?:staging/[A-Za-z0-9_-]{8,128}\.[a-z0-9]+'
                . '|final/[A-Za-z0-9_-]{8,128}/[A-Za-z0-9_-]{16,128}'
                . '-sha256-[a-f0-9]{64}\.[a-z0-9]+)$~D',
                $suffix,
            ) !== 1
        ) {
            throw new \LogicException('Refusing to operate on an object outside the owned acceptance prefix.');
        }
    }

    private function assertFixturePath(string $path): void
    {
        $workspace = realpath($this->workspace);
        $fixture = realpath($path);
        if (
            $workspace === false
            || $fixture === false
            || !is_file($fixture)
            || is_link($fixture)
            || dirname($fixture) !== $workspace
        ) {
            throw new \LogicException('Refusing to read a fixture outside the acceptance workspace.');
        }
    }

    private function removeWorkspace(): void
    {
        if (!file_exists($this->workspace) && !is_link($this->workspace)) {
            return;
        }
        $this->assertIdentity();
        if (!is_dir($this->workspace) || is_link($this->workspace)) {
            throw new \RuntimeException('Refusing to remove an invalid acceptance workspace.');
        }
        $items = new \FilesystemIterator($this->workspace, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if ($item->isLink() || !$item->isFile() || !unlink($item->getPathname())) {
                throw new \RuntimeException('Unable to remove an acceptance workspace entry.');
            }
        }
        if (!rmdir($this->workspace)) {
            throw new \RuntimeException('Unable to remove the acceptance workspace.');
        }
    }

    /** @return array{objects_before:int,objects_deleted:int,objects_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int} */
    private function emptyCleanupEvidence(): array
    {
        return [
            'objects_before' => 0,
            'objects_deleted' => 0,
            'objects_after' => 0,
            'database_before' => 0,
            'database_after' => 0,
            'workspace_before' => 0,
            'workspace_after' => 0,
        ];
    }
}
