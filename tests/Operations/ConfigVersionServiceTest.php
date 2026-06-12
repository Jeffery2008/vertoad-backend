<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Domain\Operations\ConfigVersion;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Operations\InMemoryConfigVersionRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\ConfigVersionService;

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

    public function testAssetUploadPolicyVersionsRejectInvalidPolicyValues(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));
        $valid = $this->validAssetUploadPolicyConfig();

        foreach (
            [
                'arbitrary object' => ['enabled' => true],
                'blocked extension allowed' => [
                    ...$valid,
                    'types' => [
                        ...$valid['types'],
                        'image' => [
                            ...$valid['types']['image'],
                            'allowed_content_types' => [
                                ...$valid['types']['image']['allowed_content_types'],
                                'html' => 'image/png',
                            ],
                        ],
                    ],
                ],
                'blocked content type allowed' => [
                    ...$valid,
                    'types' => [
                        ...$valid['types'],
                        'text' => [
                            ...$valid['types']['text'],
                            'allowed_content_types' => ['txt' => 'text/html'],
                            'magic_signatures' => [
                                'text/html' => [['forbid_ascii_ci' => '<script']],
                            ],
                        ],
                    ],
                ],
                'missing dangerous extension' => [
                    ...$valid,
                    'blocked_extensions' => ['html', 'htm', 'js', 'mjs'],
                ],
                'missing dangerous content type' => [
                    ...$valid,
                    'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript'],
                ],
            ] as $case => $value
        ) {
            try {
                $service->createVersion('assets.upload_policy', $value, 7);
                self::fail('Invalid assets.upload_policy value must be rejected: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith('Invalid assets.upload_policy ', $exception->getMessage());
            }
        }
    }

    public function testAssetUploadPolicyVersionAcceptsValidPolicy(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));

        $created = $service->createVersion('assets.upload_policy', $this->validAssetUploadPolicyConfig(), 7);

        self::assertSame(1, $this->value($created, 'version_number'));
        self::assertSame('assets.upload_policy', $this->value($created, 'config_key'));
        self::assertSame($this->validAssetUploadPolicyConfig(), $this->value($created, 'value'));
    }

    public function testAssetUploadPolicyRollbackRejectsInvalidHistoricalPolicyWithoutAppendingVersion(): void
    {
        $repository = new InMemoryConfigVersionRepository();
        $repository->append(new ConfigVersion(
            version_id: 'cfgv_legacy_invalid_assets_policy',
            config_key: 'assets.upload_policy',
            version_number: 1,
            value: ['enabled' => true],
            created_by_user_id: 1,
            created_at: new DateTimeImmutable(),
        ));
        $service = new ConfigVersionService($repository, new AuditLogService(new ConfigAuditRepository()));

        try {
            $service->rollback('cfgv_legacy_invalid_assets_policy', 7);
            self::fail('Invalid historical assets.upload_policy value must not be rolled back.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringStartsWith('Invalid assets.upload_policy ', $exception->getMessage());
        }

        self::assertCount(1, $repository->listByKey('assets.upload_policy'));
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

    /**
     * @return array<string, mixed>
     */
    private function validAssetUploadPolicyConfig(): array
    {
        return [
            'upload_intent_ttl_seconds' => 120,
            'blocked_extensions' => ['html', 'htm', 'js', 'mjs', 'svg'],
            'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'],
            'types' => [
                'image' => [
                    'max_bytes' => 2048,
                    'max_width' => 512,
                    'max_height' => 512,
                    'allowed_content_types' => ['png' => 'image/png'],
                    'magic_signatures' => [
                        'image/png' => [
                            ['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")],
                        ],
                    ],
                ],
                'video' => [
                    'max_bytes' => 4096,
                    'max_width' => 640,
                    'max_height' => 360,
                    'max_duration_seconds' => 15,
                    'allowed_content_types' => ['webm' => 'video/webm'],
                    'magic_signatures' => [
                        'video/webm' => [
                            ['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")],
                        ],
                    ],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1024,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => [
                        'application/json' => [
                            ['trimmed_prefix_ascii' => '{'],
                        ],
                    ],
                ],
                'text' => [
                    'max_bytes' => 512,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => [
                        'text/plain' => [
                            ['forbid_ascii_ci' => '<script'],
                        ],
                    ],
                ],
            ],
        ];
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
