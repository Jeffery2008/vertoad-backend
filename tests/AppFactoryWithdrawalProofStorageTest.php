<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use VertoAD\AppFactory;
use VertoAD\Infrastructure\Storage\AwsS3PresignedUploadSigner;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\S3BackupObjectStorage;
use VertoAD\Infrastructure\Storage\S3ObjectStorageInspector;
use VertoAD\Infrastructure\Storage\UnavailableObjectStorageInspector;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;
use VertoAD\Service\Operations\Backup\BackupSourceRegistry;

final class AppFactoryWithdrawalProofStorageTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            @unlink($path . '/config/routes.php');
            @unlink($path . '/config/settings.php');
            @rmdir($path . '/config');
            @rmdir($path);
        }
        $this->temporaryPaths = [];
    }

    public function testProductionWithdrawalProofServiceUsesDedicatedPrivateS3Storage(): void
    {
        $settings = $this->settings('prod');
        $settings['withdrawal_proofs'] = ['s3' => $this->s3('withdrawal-proofs')];

        $service = $this->service($settings);

        self::assertInstanceOf(AwsS3PresignedUploadSigner::class, $this->property($service, 'signer'));
        self::assertInstanceOf(S3ObjectStorageInspector::class, $this->property($service, 'inspector'));
    }

    public function testLocalWithdrawalProofServiceUsesDeterministicUnavailableFallback(): void
    {
        $service = $this->service($this->settings('local'));

        self::assertInstanceOf(DeterministicPresignedUploadSigner::class, $this->property($service, 'signer'));
        self::assertInstanceOf(UnavailableObjectStorageInspector::class, $this->property($service, 'inspector'));
    }

    public function testProductionBackupSourcesResolveSeparatelyFromTheBackupTarget(): void
    {
        $settings = $this->settings('prod');
        $settings['withdrawal_proofs'] = ['s3' => $this->s3('withdrawal-proofs')];
        $container = $this->container($settings);
        $registry = $container->get(BackupSourceRegistry::class);
        $target = $container->get(BackupObjectStorageInterface::class);

        self::assertInstanceOf(BackupSourceRegistry::class, $registry);
        self::assertInstanceOf(S3BackupObjectStorage::class, $target);
        $assets = $registry->storageFor(BackupSourceRegistry::ASSETS, $target);
        $proofs = $registry->storageFor(BackupSourceRegistry::WITHDRAWAL_PROOFS, $target);
        $archive = $registry->storageFor(BackupSourceRegistry::ARCHIVE, $target);
        self::assertInstanceOf(S3BackupObjectStorage::class, $assets);
        self::assertInstanceOf(S3BackupObjectStorage::class, $proofs);
        self::assertSame($assets, $archive);
        self::assertNotSame($target, $assets);
        self::assertNotSame($target, $proofs);
        self::assertNotSame($assets, $proofs);
    }

    public function testProductionRejectsMissingPartialInsecureOrOverlappingProofStorage(): void
    {
        $cases = [];
        $cases['missing'] = [$this->settings('prod'), 'Dedicated WITHDRAWAL_PROOF_S3_* storage credentials are required.'];

        $partial = $this->settings('prod');
        $partial['withdrawal_proofs'] = ['s3' => ['endpoint' => 'https://minio.example.test']];
        $cases['partial'] = [$partial, 'Dedicated WITHDRAWAL_PROOF_S3_* storage credentials are required.'];

        $small = $this->settings('prod');
        $small['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'max_inspect_bytes' => 1024]];
        $cases['small limit'] = [$small, 'WITHDRAWAL_PROOF_S3_MAX_INSPECT_BYTES must equal the payment proof policy limit.'];

        $large = $this->settings('prod');
        $large['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'max_inspect_bytes' => 20_971_520]];
        $cases['large limit'] = [$large, 'WITHDRAWAL_PROOF_S3_MAX_INSPECT_BYTES must equal the payment proof policy limit.'];

        $missingEncryption = $this->settings('prod');
        $missingEncryption['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'server_side_encryption' => '']];
        $cases['missing encryption'] = [$missingEncryption, 'WITHDRAWAL_PROOF_S3_SERVER_SIDE_ENCRYPTION must be AES256 or aws:kms.'];

        $invalidEncryption = $this->settings('prod');
        $invalidEncryption['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'server_side_encryption' => 'AES128']];
        $cases['invalid encryption'] = [$invalidEncryption, 'WITHDRAWAL_PROOF_S3_SERVER_SIDE_ENCRYPTION must be AES256 or aws:kms.'];

        $http = $this->settings('prod');
        $http['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'endpoint' => 'http://minio.example.test']];
        $cases['http'] = [$http, 'WITHDRAWAL_PROOF_S3_ENDPOINT must use HTTPS outside local/testing.'];

        $missingHost = $this->settings('prod');
        $missingHost['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'endpoint' => 'https:withdrawal-proofs']];
        $cases['missing host'] = [$missingHost, 'WITHDRAWAL_PROOF_S3_ENDPOINT must use HTTPS outside local/testing.'];

        $credentials = $this->settings('prod');
        $credentials['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'endpoint' => 'https://operator:secret@minio.example.test']];
        $cases['credentialed endpoint'] = [$credentials, 'WITHDRAWAL_PROOF_S3_ENDPOINT must not contain credentials, a query, or a fragment.'];

        $query = $this->settings('prod');
        $query['withdrawal_proofs'] = ['s3' => [...$this->s3('withdrawal-proofs'), 'endpoint' => 'https://minio.example.test?private=true']];
        $cases['query endpoint'] = [$query, 'WITHDRAWAL_PROOF_S3_ENDPOINT must not contain credentials, a query, or a fragment.'];

        $public = $this->settings('prod');
        $public['withdrawal_proofs'] = ['s3' => $this->s3('public-assets')];
        $cases['public bucket'] = [$public, 'Withdrawal proof storage must not reuse the public asset bucket.'];

        $publicDefaultPort = $this->settings('prod');
        $publicDefaultPort['withdrawal_proofs'] = ['s3' => [...$this->s3('public-assets'), 'endpoint' => 'https://minio.example.test:443/']];
        $cases['public bucket default port'] = [$publicDefaultPort, 'Withdrawal proof storage must not reuse the public asset bucket.'];

        $backup = $this->settings('prod');
        $backup['withdrawal_proofs'] = ['s3' => $this->s3('backup-target')];
        $cases['backup bucket'] = [$backup, 'Withdrawal proof storage must not reuse the backup target bucket.'];

        foreach ($cases as $name => [$settings, $message]) {
            try {
                $this->service($settings);
                self::fail('Expected invalid withdrawal proof storage: ' . $name);
            } catch (\RuntimeException $exception) {
                self::assertSame($message, $exception->getMessage(), $name);
            }
        }
    }

    /** @param array<string, mixed> $settings */
    private function service(array $settings): WithdrawalProofService
    {
        $service = $this->container($settings)->get(WithdrawalProofService::class);
        self::assertInstanceOf(WithdrawalProofService::class, $service);

        return $service;
    }

    /** @param array<string, mixed> $settings */
    private function container(array $settings): ContainerInterface
    {
        $basePath = sys_get_temp_dir() . '/vertoad-proof-storage-' . bin2hex(random_bytes(5));
        $this->temporaryPaths[] = $basePath;
        mkdir($basePath . '/config', recursive: true);
        file_put_contents($basePath . '/config/settings.php', '<?php return ' . var_export($settings, true) . ';');
        file_put_contents($basePath . '/config/routes.php', <<<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
};
PHP);

        $container = AppFactory::create($basePath)->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /** @return array<string, mixed> */
    private function settings(string $environment): array
    {
        return [
            'app' => [
                'env' => $environment,
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'storage' => ['s3' => $this->s3('public-assets')],
            'backup' => ['s3' => $this->s3('backup-target')],
            'cron' => ['token' => '', 'allowed_ips' => [], 'jobs' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function s3(string $bucket): array
    {
        return [
            'endpoint' => 'https://minio.example.test',
            'region' => 'us-east-1',
            'bucket' => $bucket,
            'access_key_id' => 'access-key',
            'secret_access_key' => 'secret-key',
            'path_style_endpoint' => true,
            'server_side_encryption' => 'AES256',
            'max_inspect_bytes' => 10_485_760,
        ];
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
