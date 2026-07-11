<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations\Backup;

use Closure;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class BackupExecutor
{
    /**
     * @param list<string> $restoreAllowedEnvironments
     * @param Closure():DateTimeImmutable|null $clock
     */
    public function __construct(
        private BackupJobRepositoryInterface $jobs,
        private MysqlBackupRunnerInterface $mysql,
        private BackupObjectStorageInterface $storage,
        private BackupSourceRegistry $sourceRegistry,
        private BackupInventory $inventory,
        private AuditLogService $audit,
        private string $baseObjectKey,
        private string $tempDirectory,
        private array $restoreAllowedEnvironments = ['staging'],
        private ?Closure $clock = null,
    ) {
    }

    public function executeNextBackup(): BackupExecutionResult
    {
        $job = $this->jobs->claimNext('backup', $this->now());
        if (!$job instanceof BackupJob) {
            return new BackupExecutionResult('backup', null, 'idle');
        }
        $directory = '';
        try {
            $directory = $this->jobDirectory($job->jobId);
            $dumpPath = $directory . DIRECTORY_SEPARATOR . 'mysql.sql';
            $this->mysql->dump($dumpPath);
            $dumpSize = filesize($dumpPath);
            $dumpHash = hash_file('sha256', $dumpPath);
            if (!is_int($dumpSize) || $dumpSize <= 0 || !is_string($dumpHash)) {
                throw new RuntimeException('Unable to measure completed MySQL backup.');
            }

            $base = $this->jobBase($job->jobId);
            $mysqlObjectKey = $base . '/mysql.sql';
            $configObjectKey = $base . '/configuration.json';
            $manifestObjectKey = $base . '/manifest.json';
            $this->storage->putFile($mysqlObjectKey, $dumpPath, 'application/sql');
            if ($this->storage->size($mysqlObjectKey) !== $dumpSize) {
                throw new RuntimeException('MySQL backup upload size verification failed.');
            }
            if (!hash_equals($dumpHash, $this->storage->sha256($mysqlObjectKey))) {
                throw new RuntimeException('MySQL backup upload checksum verification failed.');
            }

            $configJson = $this->json($this->inventory->configurationSnapshot($this->now()));
            $this->storage->putString($configObjectKey, $configJson, 'application/json');
            $configHash = hash('sha256', $configJson);
            $configSize = strlen($configJson);
            if ($this->storage->size($configObjectKey) !== $configSize) {
                throw new RuntimeException('Configuration backup upload size verification failed.');
            }
            if (!hash_equals($configHash, $this->storage->sha256($configObjectKey))) {
                throw new RuntimeException('Configuration backup upload checksum verification failed.');
            }
            $objects = [];
            foreach ($this->inventory->criticalObjects() as $index => $source) {
                $sourceStorage = $this->sourceStorage($source->sourceStorage);
                if (!$sourceStorage->exists($source->sourceKey)) {
                    throw new RuntimeException(
                        'Critical object is missing from source storage '
                        . $source->sourceStorage
                        . ': '
                        . $source->sourceKey,
                    );
                }
                [$sourceBytes, $sourceHash] = $this->measureStorageObject(
                    $sourceStorage,
                    $source->sourceKey,
                    'Critical source object',
                );
                $localPath = $this->objectTemporaryPath($directory, 'backup', $index);
                $sourceStorage->getFile($source->sourceKey, $localPath);
                $this->verifyLocalFile(
                    $localPath,
                    $sourceBytes,
                    $sourceHash,
                    'Critical source object download',
                );

                $targetKey = $this->backupObjectKey($base, $source);
                $this->storage->putFile($targetKey, $localPath, $source->contentType);
                $this->verifyStorageObject(
                    $this->storage,
                    $targetKey,
                    $sourceBytes,
                    $sourceHash,
                    'Critical object backup',
                );
                $objects[] = [
                    'source_storage' => $source->sourceStorage,
                    'source_key' => $source->sourceKey,
                    'target_key' => $targetKey,
                    'sha256' => $sourceHash,
                    'bytes' => $sourceBytes,
                    'content_type' => $source->contentType,
                ];
            }
            $payloadBytes = $dumpSize + $configSize + array_sum(array_column($objects, 'bytes'));
            $manifest = [
                'schema' => 'vertoad-backup-manifest-v2',
                'backup_id' => $job->jobId,
                'created_at' => $this->now()->format(DATE_ATOM),
                'environment' => $job->environment,
                'mysql' => [
                    'object_key' => $mysqlObjectKey,
                    'sha256' => $dumpHash,
                    'byte_count' => $dumpSize,
                ],
                'configuration' => [
                    'object_key' => $configObjectKey,
                    'sha256' => $configHash,
                    'byte_count' => $configSize,
                ],
                'objects' => $objects,
                'payload_byte_count' => $payloadBytes,
            ];
            $manifestJson = $this->json($manifest);
            $manifestHash = hash('sha256', $manifestJson);
            $this->storage->putString($manifestObjectKey, $manifestJson, 'application/json');
            if ($this->storage->size($manifestObjectKey) !== strlen($manifestJson)) {
                throw new RuntimeException('Backup manifest upload size verification failed.');
            }
            if (!hash_equals($manifestHash, $this->storage->sha256($manifestObjectKey))) {
                throw new RuntimeException('Backup manifest upload checksum verification failed.');
            }

            $completed = $this->completedBackup(
                $job,
                $manifestObjectKey,
                $manifestHash,
                $mysqlObjectKey,
                $dumpHash,
                $configObjectKey,
                count($objects),
                $payloadBytes,
            );
            $this->jobs->save($completed);
            $this->audit->record(
                action: 'operations.backup.completed',
                subjectType: 'operation_backup',
                actorUserId: $job->requestedByUserId,
                requestId: $job->requestId,
                metadata: [
                    'backup_id' => $job->jobId,
                    'byte_count' => $payloadBytes,
                    'manifest_object_key' => $manifestObjectKey,
                    'object_count' => count($objects),
                ],
            );

            return new BackupExecutionResult('backup', $job->jobId, 'completed', count($objects), $payloadBytes);
        } catch (Throwable $exception) {
            $message = $this->errorMessage($exception);
            $this->jobs->save($this->failed($job, $message));
            $this->audit->record(
                action: 'operations.backup.failed',
                subjectType: 'operation_backup',
                actorUserId: $job->requestedByUserId,
                requestId: $job->requestId,
                metadata: ['backup_id' => $job->jobId, 'error' => $message],
            );

            return new BackupExecutionResult('backup', $job->jobId, 'failed', errorMessage: $message);
        } finally {
            if ($directory !== '') {
                $this->cleanup($directory);
            }
        }
    }

    public function executeNextRestore(): BackupExecutionResult
    {
        $job = $this->jobs->claimNext('restore', $this->now());
        if (!$job instanceof BackupJob) {
            return new BackupExecutionResult('restore', null, 'idle');
        }
        $directory = '';
        try {
            $directory = $this->jobDirectory($job->jobId);
            $allowed = array_map(static fn (string $value): string => strtolower(trim($value)), $this->restoreAllowedEnvironments);
            if (!in_array(strtolower($job->environment), $allowed, true)) {
                throw new RuntimeException('Restore executor rejected the target environment.');
            }
            $source = $job->sourceBackupId === null ? null : $this->jobs->find($job->sourceBackupId);
            if (!$source instanceof BackupJob || $source->status !== 'completed' || $source->manifestObjectKey === null) {
                throw new RuntimeException('Restore source backup is not completed.');
            }
            $manifest = $this->manifest($source);
            $mysql = $manifest['mysql'];
            $configuration = $manifest['configuration'];
            $objects = $manifest['objects'];
            foreach ([$mysql, $configuration] as $requiredObject) {
                $requiredObjectKey = $requiredObject['object_key'];
                if (!$this->storage->exists($requiredObjectKey)) {
                    throw new RuntimeException('Restore preflight could not find backup object: ' . $requiredObjectKey);
                }
                $this->verifyStorageObject(
                    $this->storage,
                    $requiredObjectKey,
                    $requiredObject['byte_count'],
                    $requiredObject['sha256'],
                    'Restore preflight backup object',
                );
            }
            foreach ($objects as $object) {
                $this->sourceStorage($object['source_storage']);
                $targetKey = $object['target_key'];
                if (!$this->storage->exists($targetKey)) {
                    throw new RuntimeException('Restore preflight could not find backup object: ' . $targetKey);
                }
                $this->verifyStorageObject(
                    $this->storage,
                    $targetKey,
                    $object['bytes'],
                    $object['sha256'],
                    'Restore preflight backed-up object',
                );
            }

            $configJson = $this->storage->readString($configuration['object_key']);
            if (!hash_equals($configuration['sha256'], hash('sha256', $configJson))) {
                throw new RuntimeException('Configuration backup checksum verification failed.');
            }
            $configSnapshot = json_decode($configJson, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($configSnapshot) || ($configSnapshot['schema'] ?? null) !== 'vertoad-config-backup-v1') {
                throw new RuntimeException('Configuration backup schema is invalid.');
            }

            $dumpPath = $directory . DIRECTORY_SEPARATOR . 'mysql.sql';
            $this->storage->getFile((string) $mysql['object_key'], $dumpPath);
            $actualHash = $this->verifyLocalFile(
                $dumpPath,
                $mysql['byte_count'],
                $mysql['sha256'],
                'MySQL backup download',
            );
            $this->mysql->restore($dumpPath);
            // The dump captured the source job while it was running and predates this restore job.
            $this->jobs->save($source);
            $this->jobs->save($job);
            foreach ($objects as $index => $object) {
                $localPath = $this->objectTemporaryPath($directory, 'restore', $index);
                $this->storage->getFile($object['target_key'], $localPath);
                $this->verifyLocalFile(
                    $localPath,
                    $object['bytes'],
                    $object['sha256'],
                    'Backed-up object download',
                );
                $sourceStorage = $this->sourceStorage($object['source_storage']);
                $sourceStorage->putFile($object['source_key'], $localPath, $object['content_type']);
                $this->verifyStorageObject(
                    $sourceStorage,
                    $object['source_key'],
                    $object['bytes'],
                    $object['sha256'],
                    'Restored object',
                );
            }

            $evidenceObjectKey = rtrim($this->baseObjectKey, '/') . '/restore-evidence/' . $job->jobId . '.json';
            $evidence = [
                'schema' => 'vertoad-restore-evidence-v1',
                'restore_id' => $job->jobId,
                'backup_id' => $source->jobId,
                'environment' => $job->environment,
                'mysql_sha256' => $actualHash,
                'manifest_sha256' => $source->manifestSha256,
                'objects_restored' => count($objects),
                'completed_at' => $this->now()->format(DATE_ATOM),
                'requested_by_user_id' => $job->requestedByUserId,
                'reason' => $job->reason,
            ];
            $this->storage->putString($evidenceObjectKey, $this->json($evidence), 'application/json');
            $completed = $this->completedRestore($job, $evidenceObjectKey, count($objects));
            $this->jobs->save($completed);
            $this->audit->record(
                action: 'operations.backup.restore_completed',
                subjectType: 'operation_restore',
                actorUserId: $job->requestedByUserId,
                requestId: $job->requestId,
                metadata: [
                    'backup_id' => $source->jobId,
                    'evidence_object_key' => $evidenceObjectKey,
                    'objects_restored' => count($objects),
                    'restore_id' => $job->jobId,
                ],
            );

            return new BackupExecutionResult('restore', $job->jobId, 'completed', count($objects));
        } catch (Throwable $exception) {
            $message = $this->errorMessage($exception);
            $this->jobs->save($this->failed($job, $message));
            $this->audit->record(
                action: 'operations.backup.restore_failed',
                subjectType: 'operation_restore',
                actorUserId: $job->requestedByUserId,
                requestId: $job->requestId,
                metadata: [
                    'backup_id' => $job->sourceBackupId,
                    'error' => $message,
                    'restore_id' => $job->jobId,
                ],
            );

            return new BackupExecutionResult('restore', $job->jobId, 'failed', errorMessage: $message);
        } finally {
            if ($directory !== '') {
                $this->cleanup($directory);
            }
        }
    }

    /** @return array{mysql:array{object_key:string,sha256:string,byte_count:int},configuration:array{object_key:string,sha256:string,byte_count:int},objects:list<array{source_storage:string,source_key:string,target_key:string,sha256:string,bytes:int,content_type:string}>} */
    private function manifest(BackupJob $source): array
    {
        if (!is_string($source->manifestSha256) || !preg_match('/^[a-f0-9]{64}$/', $source->manifestSha256)) {
            throw new RuntimeException('Trusted backup manifest checksum is missing or invalid.');
        }
        $manifestJson = $this->storage->readString((string) $source->manifestObjectKey);
        if (!hash_equals($source->manifestSha256, hash('sha256', $manifestJson))) {
            throw new RuntimeException('Backup manifest checksum verification failed.');
        }
        $decoded = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== 'vertoad-backup-manifest-v2') {
            throw new RuntimeException('Backup manifest schema is invalid.');
        }
        if (($decoded['backup_id'] ?? null) !== $source->jobId) {
            throw new RuntimeException('Backup manifest identifier does not match the restore source.');
        }
        $mysql = $decoded['mysql'] ?? null;
        $configuration = $decoded['configuration'] ?? null;
        $objects = $decoded['objects'] ?? null;
        $payloadByteCount = $decoded['payload_byte_count'] ?? null;
        if (!is_array($mysql) || !is_array($configuration) || !is_array($objects) || !is_int($payloadByteCount) || $payloadByteCount < 0) {
            throw new RuntimeException('Backup manifest payload is incomplete.');
        }
        if (
            !is_string($mysql['object_key'] ?? null)
            || trim((string) $mysql['object_key']) === ''
            || !preg_match('/^[a-f0-9]{64}$/', (string) ($mysql['sha256'] ?? ''))
            || !is_int($mysql['byte_count'] ?? null)
            || $mysql['byte_count'] <= 0
        ) {
            throw new RuntimeException('Backup manifest MySQL payload is invalid.');
        }
        if (
            !is_string($configuration['object_key'] ?? null)
            || trim((string) $configuration['object_key']) === ''
            || !preg_match('/^[a-f0-9]{64}$/', (string) ($configuration['sha256'] ?? ''))
            || !is_int($configuration['byte_count'] ?? null)
            || $configuration['byte_count'] <= 0
        ) {
            throw new RuntimeException('Backup manifest configuration payload is invalid.');
        }
        $normalizedObjects = [];
        $seenSources = [];
        $seenTargets = [];
        $expectedBase = $this->jobBase($source->jobId);
        foreach ($objects as $object) {
            if (
                !is_array($object)
                || !is_string($object['source_storage'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $object['source_storage'])
                || !is_string($object['source_key'] ?? null)
                || trim((string) $object['source_key']) === ''
                || !is_string($object['target_key'] ?? null)
                || trim((string) $object['target_key']) === ''
                || !preg_match('/^[a-f0-9]{64}$/', (string) ($object['sha256'] ?? ''))
                || !is_int($object['bytes'] ?? null)
                || $object['bytes'] < 0
                || !is_string($object['content_type'] ?? null)
            ) {
                throw new RuntimeException('Backup manifest object inventory is invalid.');
            }
            $descriptor = new BackupSourceDescriptor(
                (string) $object['source_storage'],
                (string) $object['source_key'],
                (string) $object['content_type'],
            );
            $this->sourceStorage($descriptor->sourceStorage);
            $targetKey = trim((string) $object['target_key']);
            if ($targetKey !== $this->backupObjectKey($expectedBase, $descriptor)) {
                throw new RuntimeException('Backup manifest object target key is invalid.');
            }
            $sourceIdentity = $descriptor->sourceStorage . "\0" . $descriptor->sourceKey;
            if (isset($seenSources[$sourceIdentity]) || isset($seenTargets[$targetKey])) {
                throw new RuntimeException('Backup manifest object inventory contains duplicates.');
            }
            $seenSources[$sourceIdentity] = true;
            $seenTargets[$targetKey] = true;
            $normalizedObjects[] = [
                'source_storage' => $descriptor->sourceStorage,
                'source_key' => $descriptor->sourceKey,
                'target_key' => $targetKey,
                'sha256' => (string) $object['sha256'],
                'bytes' => $object['bytes'],
                'content_type' => $descriptor->contentType,
            ];
        }
        $calculatedPayloadBytes = $mysql['byte_count'] + $configuration['byte_count'];
        foreach ($normalizedObjects as $object) {
            $calculatedPayloadBytes += $object['bytes'];
        }
        if (
            $source->manifestObjectKey !== $expectedBase . '/manifest.json'
            || $mysql['object_key'] !== $expectedBase . '/mysql.sql'
            || $configuration['object_key'] !== $expectedBase . '/configuration.json'
            || $source->mysqlObjectKey !== $mysql['object_key']
            || $source->mysqlSha256 !== $mysql['sha256']
            || $source->configObjectKey !== $configuration['object_key']
            || $source->objectCount !== count($normalizedObjects)
            || $source->byteCount !== $payloadByteCount
            || $payloadByteCount !== $calculatedPayloadBytes
            || $source->environment !== ($decoded['environment'] ?? null)
        ) {
            throw new RuntimeException('Backup manifest does not match trusted job metadata.');
        }

        return [
            'mysql' => [
                'object_key' => (string) $mysql['object_key'],
                'sha256' => (string) $mysql['sha256'],
                'byte_count' => $mysql['byte_count'],
            ],
            'configuration' => [
                'object_key' => (string) $configuration['object_key'],
                'sha256' => (string) $configuration['sha256'],
                'byte_count' => $configuration['byte_count'],
            ],
            'objects' => $normalizedObjects,
        ];
    }

    private function completedBackup(
        BackupJob $job,
        string $manifestObjectKey,
        string $manifestSha256,
        string $mysqlObjectKey,
        string $mysqlSha256,
        string $configObjectKey,
        int $objectCount,
        int $byteCount,
    ): BackupJob {
        return new BackupJob(
            jobId: $job->jobId,
            jobType: $job->jobType,
            sourceBackupId: null,
            status: 'completed',
            requestedByUserId: $job->requestedByUserId,
            requestId: $job->requestId,
            environment: $job->environment,
            reason: null,
            manifestObjectKey: $manifestObjectKey,
            manifestSha256: $manifestSha256,
            mysqlObjectKey: $mysqlObjectKey,
            mysqlSha256: $mysqlSha256,
            configObjectKey: $configObjectKey,
            evidenceObjectKey: null,
            objectCount: $objectCount,
            byteCount: $byteCount,
            errorMessage: null,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            completedAt: $this->now(),
        );
    }

    private function completedRestore(BackupJob $job, string $evidenceObjectKey, int $objectCount): BackupJob
    {
        return new BackupJob(
            jobId: $job->jobId,
            jobType: $job->jobType,
            sourceBackupId: $job->sourceBackupId,
            status: 'completed',
            requestedByUserId: $job->requestedByUserId,
            requestId: $job->requestId,
            environment: $job->environment,
            reason: $job->reason,
            manifestObjectKey: $job->manifestObjectKey,
            manifestSha256: $job->manifestSha256,
            mysqlObjectKey: $job->mysqlObjectKey,
            mysqlSha256: $job->mysqlSha256,
            configObjectKey: $job->configObjectKey,
            evidenceObjectKey: $evidenceObjectKey,
            objectCount: $objectCount,
            byteCount: 0,
            errorMessage: null,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            completedAt: $this->now(),
        );
    }

    private function failed(BackupJob $job, string $message): BackupJob
    {
        return new BackupJob(
            jobId: $job->jobId,
            jobType: $job->jobType,
            sourceBackupId: $job->sourceBackupId,
            status: 'failed',
            requestedByUserId: $job->requestedByUserId,
            requestId: $job->requestId,
            environment: $job->environment,
            reason: $job->reason,
            manifestObjectKey: $job->manifestObjectKey,
            manifestSha256: $job->manifestSha256,
            mysqlObjectKey: $job->mysqlObjectKey,
            mysqlSha256: $job->mysqlSha256,
            configObjectKey: $job->configObjectKey,
            evidenceObjectKey: $job->evidenceObjectKey,
            objectCount: $job->objectCount,
            byteCount: $job->byteCount,
            errorMessage: $message,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            completedAt: $this->now(),
        );
    }

    private function jobBase(string $jobId): string
    {
        $base = rtrim(trim($this->baseObjectKey), '/');
        if ($base === '') {
            throw new RuntimeException('Backup base object key is required.');
        }

        return $base . '/' . $jobId;
    }

    private function backupObjectKey(string $base, BackupSourceDescriptor $source): string
    {
        $path = parse_url($source->sourceKey, PHP_URL_PATH);
        $name = basename(is_string($path) ? $path : $source->sourceKey);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'object';

        return $base
            . '/objects/'
            . $source->sourceStorage
            . '/'
            . hash('sha256', $source->sourceKey)
            . '/'
            . $name;
    }

    private function sourceStorage(string $sourceStorage): BackupObjectStorageInterface
    {
        return $this->sourceRegistry->storageFor($sourceStorage, $this->storage);
    }

    /** @return array{0:int,1:string} */
    private function measureStorageObject(
        BackupObjectStorageInterface $storage,
        string $objectKey,
        string $label,
    ): array {
        $bytes = $storage->size($objectKey);
        $sha256 = $storage->sha256($objectKey);
        if ($bytes < 0) {
            throw new RuntimeException($label . ' size is invalid: ' . $objectKey);
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException($label . ' checksum is invalid: ' . $objectKey);
        }

        return [$bytes, $sha256];
    }

    private function verifyStorageObject(
        BackupObjectStorageInterface $storage,
        string $objectKey,
        int $expectedBytes,
        string $expectedSha256,
        string $label,
    ): void {
        if ($storage->size($objectKey) !== $expectedBytes) {
            throw new RuntimeException($label . ' size verification failed: ' . $objectKey);
        }
        if (!hash_equals($expectedSha256, $storage->sha256($objectKey))) {
            throw new RuntimeException($label . ' checksum verification failed: ' . $objectKey);
        }
    }

    private function verifyLocalFile(
        string $path,
        int $expectedBytes,
        string $expectedSha256,
        string $label,
    ): string {
        $bytes = @filesize($path);
        if (!is_int($bytes) || $bytes !== $expectedBytes) {
            throw new RuntimeException($label . ' size verification failed.');
        }
        $sha256 = @hash_file('sha256', $path);
        if (!is_string($sha256) || !hash_equals($expectedSha256, $sha256)) {
            throw new RuntimeException($label . ' checksum verification failed.');
        }

        return $sha256;
    }

    private function objectTemporaryPath(string $directory, string $operation, int $index): string
    {
        return $directory . DIRECTORY_SEPARATOR . sprintf('%s-object-%06d.tmp', $operation, $index);
    }

    private function jobDirectory(string $jobId): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{7,63}$/', $jobId)) {
            throw new RuntimeException('Backup job identifier is invalid.');
        }
        $root = rtrim(trim($this->tempDirectory), "\\/");
        if ($root === '') {
            throw new RuntimeException('Backup temporary directory is required.');
        }
        $directory = $root . DIRECTORY_SEPARATOR . $jobId;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup temporary directory.');
        }

        return $directory;
    }

    private function cleanup(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = @scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->cleanup($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function errorMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return substr($message === '' ? $exception::class : $message, 0, 2000);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock === null ? new DateTimeImmutable() : ($this->clock)();
    }
}
