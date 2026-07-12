<?php

declare(strict_types=1);

namespace VertoAD\Scripts\S3Readiness;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VertoAD\Infrastructure\Storage\S3EncryptionPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class S3ReadinessProfile
{
    public S3EncryptionPolicy $encryption;
    public ?string $serverSideEncryption;

    public function __construct(
        public string $name,
        public string $endpoint,
        public string $region,
        public string $bucket,
        public string $accessKeyId,
        public string $secretAccessKey,
        public bool $pathStyleEndpoint,
        S3EncryptionPolicy|string|null $encryption,
    ) {
        if (!in_array($this->name, ['asset', 'withdrawal-proof', 'backup'], true)) {
            throw new InvalidArgumentException('Unknown S3 readiness profile.');
        }

        $parts = parse_url($this->endpoint);
        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('S3 endpoint must be an HTTP(S) origin without credentials, query, or fragment.');
        }
        $this->encryption = $encryption instanceof S3EncryptionPolicy
            ? $encryption
            : S3EncryptionPolicy::fromConfig(
                ['endpoint' => $this->endpoint, 'server_side_encryption' => $encryption ?? ''],
                strtoupper(str_replace('-', ' ', $this->name)) . ' S3',
            );
        $this->serverSideEncryption = $this->encryption->mode;
        if ($this->bucket === '' || $this->accessKeyId === '' || $this->secretAccessKey === '') {
            throw new InvalidArgumentException('S3 bucket and credentials are required.');
        }
    }

    /** @param array<string, string> $environment */
    public static function fromEnvironment(array $environment, string $name): self
    {
        $mapping = match ($name) {
            'asset' => ['S3', 'ASSET_S3_SERVER_SIDE_ENCRYPTION', ''],
            'withdrawal-proof' => ['WITHDRAWAL_PROOF_S3', 'WITHDRAWAL_PROOF_S3_SERVER_SIDE_ENCRYPTION', 'AES256'],
            'backup' => ['BACKUP_S3', 'BACKUP_S3_SERVER_SIDE_ENCRYPTION', 'AES256'],
            default => throw new InvalidArgumentException('Unknown S3 readiness profile.'),
        };
        [$prefix, $encryptionKey, $defaultEncryption] = $mapping;
        $endpoint = rtrim(trim((string) ($environment[$prefix . '_ENDPOINT'] ?? '')), '/');
        $appEnvironment = strtolower(trim((string) ($environment['APP_ENV'] ?? 'local')));
        if (in_array($appEnvironment, ['staging', 'prod', 'production'], true)
            && !str_starts_with(strtolower($endpoint), 'https://')) {
            throw new InvalidArgumentException('Staging and production S3 endpoints must use HTTPS.');
        }
        $encryption = trim((string) ($environment[$encryptionKey] ?? $defaultEncryption));
        $encryptionPolicy = S3EncryptionPolicy::fromConfig(
            ['endpoint' => $endpoint, 'server_side_encryption' => $encryption],
            strtoupper(str_replace('-', ' ', $name)) . ' S3',
        );

        return new self(
            name: $name,
            endpoint: $endpoint,
            region: trim((string) ($environment[$prefix . '_REGION'] ?? 'auto')) ?: 'auto',
            bucket: trim((string) ($environment[$prefix . '_BUCKET'] ?? '')),
            accessKeyId: trim((string) ($environment[$prefix . '_ACCESS_KEY_ID'] ?? '')),
            secretAccessKey: (string) ($environment[$prefix . '_SECRET_ACCESS_KEY'] ?? ''),
            pathStyleEndpoint: filter_var(
                $environment[$prefix . '_PATH_STYLE_ENDPOINT'] ?? 'true',
                FILTER_VALIDATE_BOOL,
            ),
            encryption: $encryptionPolicy,
        );
    }

    /** @return array<string, mixed> */
    public function clientConfiguration(): array
    {
        return [
            'version' => 'latest',
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->pathStyleEndpoint,
            'credentials' => new Credentials($this->accessKeyId, $this->secretAccessKey),
        ];
    }
}

final class S3ReadinessProbe
{
    /** @var \Closure(): string */
    private \Closure $nonceFactory;

    /** @param (callable(): string)|null $nonceFactory */
    public function __construct(
        private readonly S3ReadinessProfile $profile,
        private readonly S3Client $client,
        private readonly ClientInterface $httpClient,
        ?callable $nonceFactory = null,
    ) {
        $this->nonceFactory = \Closure::fromCallable(
            $nonceFactory ?? static fn (): string => bin2hex(random_bytes(16)),
        );
    }

    /** @return array<string, string|int> */
    public function run(): array
    {
        $nonce = ($this->nonceFactory)();
        if (preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $nonce) !== 1) {
            throw new RuntimeException('S3 readiness nonce factory returned an unsafe value.');
        }

        $baseKey = 'vertoad-readiness/' . $this->profile->name . '/' . $nonce;
        $directKey = $baseKey . '/direct.txt';
        $presignedKey = $baseKey . '/presigned.txt';
        $directBody = 'vertoad-s3-readiness:' . $nonce . ':direct';
        $presignedBody = 'vertoad-s3-readiness:' . $nonce . ':presigned';
        $primaryFailure = null;

        try {
            $this->client->headBucket(['Bucket' => $this->profile->bucket]);
            $this->client->putObject($this->putParameters($directKey, $directBody));
            $this->verifyObject($directKey, $directBody);
            $this->putPresigned($presignedKey, $presignedBody);
            $this->verifyObject($presignedKey, $presignedBody);
        } catch (Throwable $exception) {
            $primaryFailure = $exception;
        }

        $cleanupFailures = $this->cleanup([$directKey, $presignedKey]);
        if ($primaryFailure !== null) {
            $message = 'S3 readiness probe failed.';
            if ($cleanupFailures !== []) {
                $message .= ' Probe object cleanup also failed.';
            }
            throw new RuntimeException($message, previous: $primaryFailure);
        }
        if ($cleanupFailures !== []) {
            throw new RuntimeException('S3 readiness probe object cleanup failed.');
        }

        return [
            'profile' => $this->profile->name,
            'bucket' => $this->profile->bucket,
            'direct_round_trip' => 'passed',
            'presigned_put_round_trip' => 'passed',
            'cleanup' => 'passed',
            'probe_objects_remaining' => 0,
            'encryption_evidence' => $this->profile->encryption->readinessEvidence(),
        ];
    }

    /** @return array<string, mixed> */
    private function putParameters(string $key, string $body): array
    {
        $parameters = [
            'Bucket' => $this->profile->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => 'text/plain',
            'Metadata' => ['vertoad-readiness' => 'true'],
        ];
        return array_merge($parameters, $this->profile->encryption->putParameters());
    }

    private function putPresigned(string $key, string $body): void
    {
        $parameters = $this->putParameters($key, $body);
        unset($parameters['Body']);
        $command = $this->client->getCommand('PutObject', $parameters);
        $request = $this->client->createPresignedRequest($command, '+10 minutes')
            ->withHeader('Content-Type', 'text/plain')
            ->withBody(Utils::streamFor($body));
        $response = $this->httpClient->send($request, ['http_errors' => false]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('Presigned S3 PUT returned a non-success HTTP status.');
        }
    }

    private function verifyObject(string $key, string $expectedBody): void
    {
        $head = $this->client->headObject(['Bucket' => $this->profile->bucket, 'Key' => $key]);
        if ((int) ($head['ContentLength'] ?? -1) !== strlen($expectedBody)) {
            throw new RuntimeException('S3 readiness object length did not match the uploaded payload.');
        }
        $this->profile->encryption->assertMetadata(
            $head->toArray(),
            'S3 readiness object does not use the required server-side encryption.',
        );
        $object = $this->client->getObject([
            'Bucket' => $this->profile->bucket,
            'Key' => $key,
        ]);
        $headContentType = $this->resultContentType($head);
        $getContentType = $this->resultContentType($object);
        if ($headContentType !== '' && $getContentType !== '' && $headContentType !== $getContentType) {
            throw new RuntimeException('S3 readiness object content type changed between HEAD and GET.');
        }
        $contentType = $headContentType !== '' ? $headContentType : $getContentType;
        if ($contentType !== 'text/plain') {
            throw new RuntimeException('S3 readiness object content type did not match the upload contract for ' . basename($key) . ' (observed: ' . ($contentType === '' ? '<missing>' : $contentType) . ').');
        }
        $body = (string) ($object['Body'] ?? '');
        if (!hash_equals(hash('sha256', $expectedBody), hash('sha256', $body))) {
            throw new RuntimeException('S3 readiness object content checksum did not match the uploaded payload.');
        }
    }

    private function resultContentType(Result $result): string
    {
        $value = $result['ContentType'] ?? null;
        if ($this->normalizeContentType($value) !== '') {
            return $this->normalizeContentType($value);
        }
        $metadata = $result['@metadata'] ?? null;
        $headers = is_array($metadata) && is_array($metadata['headers'] ?? null)
            ? $metadata['headers']
            : [];
        $value = $headers['content-type'] ?? $headers['Content-Type'] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $this->normalizeContentType($value);
    }

    private function normalizeContentType(mixed $value): string
    {
        return strtolower(trim(explode(';', (string) $value, 2)[0]));
    }

    /**
     * @param list<string> $keys
     * @return list<Throwable>
     */
    private function cleanup(array $keys): array
    {
        $failures = [];
        foreach ($keys as $key) {
            try {
                $this->client->deleteObject(['Bucket' => $this->profile->bucket, 'Key' => $key]);
                $this->client->headObject(['Bucket' => $this->profile->bucket, 'Key' => $key]);
                $failures[] = new RuntimeException('S3 readiness probe object remained after deletion.');
            } catch (AwsException $exception) {
                if (!$this->isNotFound($exception)) {
                    $failures[] = $exception;
                }
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        return $failures;
    }

    private function isNotFound(AwsException $exception): bool
    {
        return $exception->getStatusCode() === 404
            || in_array($exception->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
    }
}

/** @param list<string> $arguments */
function main(array $arguments): int
{
    try {
        $envFile = dirname(__DIR__) . '/.env';
        $profileName = 'asset';
        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--help') {
                fwrite(STDOUT, "Usage: php scripts/s3-readiness.php [--env-file=PATH] [--profile=asset|withdrawal-proof|backup]\n");
                return 0;
            }
            if (str_starts_with($argument, '--env-file=')) {
                $envFile = substr($argument, strlen('--env-file='));
                continue;
            }
            if (str_starts_with($argument, '--profile=')) {
                $profileName = substr($argument, strlen('--profile='));
                continue;
            }

            throw new InvalidArgumentException('Unknown argument.');
        }

        $envFile = trim($envFile);
        if ($envFile === '' || !is_file($envFile)) {
            throw new RuntimeException('S3 readiness env file was not found.');
        }
        $environment = Dotenv::createArrayBacked(dirname($envFile), basename($envFile))->load();
        $profile = S3ReadinessProfile::fromEnvironment($environment, $profileName);
        $client = new S3Client($profile->clientConfiguration());
        $result = (new S3ReadinessProbe($profile, $client, new Client()))->run();
        foreach ($result as $key => $value) {
            fwrite(STDOUT, $key . '=' . $value . "\n");
        }
        fwrite(STDOUT, "s3_readiness=passed\n");

        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 's3_readiness_failed=' . safeErrorMessage($exception) . "\n");

        return 1;
    }
}

function safeErrorMessage(Throwable $exception): string
{
    $messages = [];
    $current = $exception;
    for ($depth = 0; $current !== null && $depth < 4; $depth++) {
        $message = trim($current->getMessage());
        if ($message !== '' && !in_array($message, $messages, true)) {
            $messages[] = $message;
        }
        $current = $current->getPrevious();
    }
    $message = implode(' Cause: ', $messages);
    $message = preg_replace('/([?&](?:X-Amz-(?:Credential|Signature|Security-Token)|AWSAccessKeyId)=)[^&\s]+/i', '$1[redacted]', $message)
        ?? 'S3 readiness failed.';
    $message = preg_replace('/(Credential=)[^,&\s]+/i', '$1[redacted]', $message) ?? $message;

    return $message === '' ? 'S3 readiness failed.' : $message;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main($argv));
}
