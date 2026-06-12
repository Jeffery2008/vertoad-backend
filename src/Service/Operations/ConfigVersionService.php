<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use RuntimeException;
use VertoAD\Domain\Operations\ConfigVersion;
use VertoAD\Repository\Operations\ConfigVersionRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class ConfigVersionService
{
    private const ASSET_UPLOAD_POLICY_KEY = 'assets.upload_policy';
    private const REQUIRED_BLOCKED_EXTENSIONS = ['html', 'htm', 'js', 'mjs', 'svg'];
    private const REQUIRED_BLOCKED_CONTENT_TYPES = ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'];

    public function __construct(
        private ConfigVersionRepositoryInterface $versions,
        private AuditLogService $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    public function createVersion(string $configKey, array $value, int $createdByUserId): array
    {
        return $this->create($configKey, $value, $createdByUserId)->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listVersions(string $configKey): array
    {
        $this->assertValidKey($configKey);

        return array_map(static fn (ConfigVersion $version): array => $version->toArray(), $this->versions->listByKey($configKey));
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(string $versionId, int $createdByUserId): array
    {
        $target = $this->versions->find($versionId);
        if ($target === null) {
            throw new RuntimeException('Config version not found.');
        }

        $created = $this->create($target->config_key, $target->value, $createdByUserId);
        $this->audit->record(
            action: 'operations.config.rollback',
            subjectType: 'config_version',
            actorUserId: $createdByUserId,
            metadata: [
                'created_version_id' => $created->version_id,
                'config_key' => $created->config_key,
                'rolled_back_to_version_id' => $target->version_id,
            ],
        );

        return $created->toArray();
    }

    /**
     * @param array<string, mixed> $value
     */
    private function create(string $configKey, array $value, int $createdByUserId): ConfigVersion
    {
        $this->assertValidKey($configKey);
        $this->assertValidValue($configKey, $value);
        $versionNumber = $this->versions->nextVersionNumber($configKey);

        return $this->versions->append(new ConfigVersion(
            version_id: 'cfgv_' . sha1($configKey . '|' . $versionNumber . '|' . json_encode($value, JSON_THROW_ON_ERROR)),
            config_key: $configKey,
            version_number: $versionNumber,
            value: $value,
            created_by_user_id: $createdByUserId,
            created_at: new DateTimeImmutable(),
        ));
    }

    private function assertValidKey(string $configKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $configKey) !== 1) {
            throw new InvalidArgumentException('Config key must use dot-separated lowercase identifiers.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidValue(string $configKey, array $value): void
    {
        if ($value === []) {
            throw new InvalidArgumentException('Config value must not be empty.');
        }

        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'password') || str_contains($normalized, 'secret') || str_contains($normalized, 'token')) {
                throw new InvalidArgumentException('Secret config values must stay in environment secrets.');
            }

            if (is_array($item)) {
                $this->assertNoSecretKeys($item);
            }
        }

        if ($configKey === self::ASSET_UPLOAD_POLICY_KEY) {
            $this->assertValidAssetUploadPolicy($value);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertNoSecretKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'password') || str_contains($normalized, 'secret') || str_contains($normalized, 'token')) {
                throw new InvalidArgumentException('Secret config values must stay in environment secrets.');
            }

            if (is_array($item)) {
                $this->assertNoSecretKeys($item);
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidAssetUploadPolicy(array $value): void
    {
        try {
            $policy = AssetUploadPolicy::fromArray($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid assets.upload_policy ' . $exception->getMessage(), 0, $exception);
        }

        foreach (self::REQUIRED_BLOCKED_EXTENSIONS as $extension) {
            if (!$policy->isBlockedExtension($extension)) {
                throw new InvalidArgumentException('Invalid assets.upload_policy must block dangerous extension ' . $extension . '.');
            }
        }

        foreach (self::REQUIRED_BLOCKED_CONTENT_TYPES as $contentType) {
            if (!$policy->isBlockedContentType($contentType)) {
                throw new InvalidArgumentException('Invalid assets.upload_policy must block dangerous content type ' . $contentType . '.');
            }
        }

        foreach (AssetType::cases() as $assetType) {
            foreach ($policy->allowedContentTypes($assetType) as $extension => $contentType) {
                if ($policy->isBlockedExtension($extension)) {
                    throw new InvalidArgumentException('Invalid assets.upload_policy cannot allow blocked extension ' . $extension . '.');
                }

                if ($policy->isBlockedContentType($contentType)) {
                    throw new InvalidArgumentException('Invalid assets.upload_policy cannot allow blocked content type ' . $contentType . '.');
                }
            }
        }
    }
}
