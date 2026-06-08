<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class ConfigVersionServiceTest extends TestCase
{
    public function testCreatesAndListsConfigVersionsWithImmutableVersionMetadata(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryConfigVersionRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\ConfigVersionService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new ConfigAuditRepository()));

        $first = $service->createVersion('security.rate_limit', ['limit' => 60, 'window_seconds' => 60], 7);
        $second = $service->createVersion('security.rate_limit', ['limit' => 100, 'window_seconds' => 60], 7);
        $versions = $service->listVersions('security.rate_limit');

        self::assertSame(1, $this->value($first, 'version_number'));
        self::assertSame(2, $this->value($second, 'version_number'));
        self::assertSame('security.rate_limit', $this->value($second, 'config_key'));
        self::assertSame(['limit' => 100, 'window_seconds' => 60], $this->value($second, 'value'));
        self::assertSame(7, $this->value($second, 'created_by_user_id'));
        self::assertCount(2, $versions);
    }

    public function testRejectsInvalidConfigKeysAndValues(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryConfigVersionRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\ConfigVersionService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new ConfigAuditRepository()));

        foreach (
            [
                ['', ['enabled' => true]],
                ['../secrets', ['enabled' => true]],
                ['security.rate_limit', []],
                ['security.rate_limit', ['password' => 'must-not-store-secret']],
            ] as [$key, $value]
        ) {
            try {
                $service->createVersion($key, $value, 7);
                self::fail('Invalid config key/value pair must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRollbackCreatesNewVersionFromHistoryAndWritesAuditMetadata(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryConfigVersionRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\ConfigVersionService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $auditRepository = new ConfigAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));
        $first = $service->createVersion('webhooks.timeout', ['seconds' => 10], 7);
        $service->createVersion('webhooks.timeout', ['seconds' => 20], 7);

        $rolledBack = $service->rollback((string) $this->value($first, 'version_id'), 11);

        self::assertSame(3, $this->value($rolledBack, 'version_number'));
        self::assertSame(['seconds' => 10], $this->value($rolledBack, 'value'));
        self::assertSame(11, $this->value($rolledBack, 'created_by_user_id'));
        self::assertSame('operations.config.rollback', $auditRepository->entries[0]->action ?? null);
        self::assertSame('config_version', $auditRepository->entries[0]->subjectType ?? null);
        self::assertSame((string) $this->value($first, 'version_id'), $auditRepository->entries[0]->metadata['rolled_back_to_version_id'] ?? null);
        self::assertSame((string) $this->value($rolledBack, 'version_id'), $auditRepository->entries[0]->metadata['created_version_id'] ?? null);
    }

    public function testRollbackRejectsMissingVersionAndNestedSecretValues(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\Operations\\InMemoryConfigVersionRepository';
        $serviceClass = 'VertoAD\\Service\\Operations\\ConfigVersionService';
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new ConfigAuditRepository()));

        try {
            $service->createVersion('security.rate_limit', ['nested' => ['secret' => 'nope']], 7);
            self::fail('Nested secret values must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Secret config values must stay in environment secrets.', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config version not found.');

        $service->rollback('missing', 7);
    }

    private function value(mixed $record, string $key): mixed
    {
        if (is_array($record)) {
            return $record[$key] ?? null;
        }

        if (is_object($record)) {
            return $record->{$key} ?? null;
        }

        return null;
    }
}

final class ConfigAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
