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
            $sourceKeys = $this->inventory->criticalObjectKeys();
            $sourceObjects = [];
            foreach ($sourceKeys as $sourceKey) {
                if (!$this->storage->exists($sourceKey)) {
                    throw new RuntimeException('Critical object is missing from primary storage: ' . $sourceKey);
                }
                $sourceObjects[$sourceKey] = [
                    'byte_count' => $this->storage->size($sourceKey),
                    'sha256' => $this->storage->sha256($sourceKey),
                ];
            }

            $objects = [];
            foreach ($sourceKeys as $sourceKey) {
                $backupObjectKey = $this->backupObjectKey($base, $sourceKey);
                $this->storage->copy($sourceKey, $backupObjectKey);
                if ($this->storage->size($backupObjectKey) !== $sourceObjects[$sourceKey]['byte_count']) {
                    throw new RuntimeException('Critical object backup size verification failed: ' . $sourceKey);
                }
                if (!hash_equals($sourceObjects[$sourceKey]['sha256'], $this->storage->sha256($backupObjectKey))) {
                    throw new RuntimeException('Critical object backup checksum verification failed: ' . $sourceKey);
                }
                $objects[] = [
                    'source_object_key' => $sourceKey,
                    'backup_object_key' => $backupObjectKey,
                    'sha256' => $sourceObjects[$sourceKey]['sha256'],
                    'byte_count' => $sourceObjects[$sourceKey]['byte_count'],
                ];
            }
            $payloadBytes = $dumpSize + $configSize + array_sum(array_column($sourceObjects, 'byte_count'));
            $manifest = [
                'schema' => 'vertoad-backup-manifest-v1',
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
                if ($this->storage->size($requiredObjectKey) !== $requiredObject['byte_count']) {
                    throw new RuntimeException('Restore preflight backup object size verification failed: ' . $requiredObjectKey);
                }
            }
            foreach ($objects as $object) {
                $backupObjectKey = $object['backup_object_key'];
                if (!$this->storage->exists($backupObjectKey)) {
                    throw new RuntimeException('Restore preflight could not find copied object: ' . $backupObjectKey);
                }
                if ($this->storage->size($backupObjectKey) !== $object['byte_count']) {
                    throw new RuntimeException('Restore preflight copied object size verification failed: ' . $backupObjectKey);
                }
                if (!hash_equals($object['sha256'], $this->storage->sha256($backupObjectKey))) {
                    throw new RuntimeException('Restore preflight copied object checksum verification failed: ' . $backupObjectKey);
                }
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
            $actualHash = hash_file('sha256', $dumpPath);
            if (!is_string($actualHash) || !hash_equals((string) $mysql['sha256'], $actualHash)) {
                throw new RuntimeException('MySQL backup checksum verification failed.');
            }
            $this->mysql->restore($dumpPath);
            // The dump captured the source job while it was running and predates this restore job.
            $this->jobs->save($source);
            $this->jobs->save($job);
            foreach ($objects as $object) {
                $this->storage->copy(
                    $object['backup_object_key'],
                    $object['source_object_key'],
                );
                if ($this->storage->size($object['source_object_key']) !== $object['byte_count']) {
                    throw new RuntimeException('Restored object size verification failed: ' . $object['source_object_key']);
                }
                if (!hash_equals($object['sha256'], $this->storage->sha256($object['source_object_key']))) {
                    throw new RuntimeException('Restored object checksum verification failed: ' . $object['source_object_key']);
                }
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

    /** @return array{mysql:array{object_key:string,sha256:string,byte_count:int},configuration:array{object_key:string,sha256:string,byte_count:int},objects:list<array{source_object_key:string,backup_object_key:string,sha256:string,byte_count:int}>} */
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
        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== 'vertoad-backup-manifest-v1') {
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
        foreach ($objects as $object) {
            if (
                !is_array($object)
                || !is_string($object['source_object_key'] ?? null)
                || trim((string) $object['source_object_key']) === ''
                || !is_string($object['backup_object_key'] ?? null)
                || trim((string) $object['backup_object_key']) === ''
                || !preg_match('/^[a-f0-9]{64}$/', (string) ($object['sha256'] ?? ''))
                || !is_int($object['byte_count'] ?? null)
                || $object['byte_count'] < 0
            ) {
                throw new RuntimeException('Backup manifest object inventory is invalid.');
            }
            $normalizedObjects[] = [
                'source_object_key' => (string) $object['source_object_key'],
                'backup_object_key' => (string) $object['backup_object_key'],
                'sha256' => (string) $object['sha256'],
                'byte_count' => $object['byte_count'],
            ];
        }
        if (
            $source->mysqlObjectKey !== $mysql['object_key']
            || $source->mysqlSha256 !== $mysql['sha256']
            || $source->configObjectKey !== $configuration['object_key']
            || $source->objectCount !== count($normalizedObjects)
            || $source->byteCount !== $payloadByteCount
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

    private function backupObjectKey(string $base, string $sourceObjectKey): string
    {
        $path = parse_url($sourceObjectKey, PHP_URL_PATH);
        $name = basename(is_string($path) ? $path : $sourceObjectKey);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'object';

        return $base . '/objects/' . hash('sha256', $sourceObjectKey) . '/' . $name;
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
