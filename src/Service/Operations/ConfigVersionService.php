<?php

declare(strict_types=1);

namespace VertoAD\Service\Operations;

use DateTimeImmutable;
use InvalidArgumentException;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\AssetUploadPolicy;
use RuntimeException;
use VertoAD\Domain\Operations\ConfigVersion;
use VertoAD\Domain\Review\AiReviewPolicy;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Domain\Webhooks\WebhookDeliveryPolicy;
use VertoAD\Repository\Operations\ConfigVersionRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class ConfigVersionService
{
    private const DEFAULT_REVENUE_SHARE_KEY = 'billing.default_revenue_share';
    private const RATE_LIMIT_KEY = 'security.rate_limit';
    private const ATTRIBUTION_DEFAULT_WINDOW_KEY = 'attribution.default_window_seconds';
    private const SERVING_EVENT_VALIDATION_KEY = 'serving.event_validation';
    private const ASSET_UPLOAD_POLICY_KEY = 'assets.upload_policy';
    private const AI_REVIEW_POLICY_KEY = 'review.ai_policy';
    private const WEBHOOK_DELIVERY_POLICY_KEY = 'webhook.delivery_policy';
    private const TURNSTILE_POLICY_KEY = 'security.turnstile_policy';
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

        $created = $this->versions->append(new ConfigVersion(
            version_id: 'cfgv_' . sha1($configKey . '|' . $versionNumber . '|' . json_encode($value, JSON_THROW_ON_ERROR)),
            config_key: $configKey,
            version_number: $versionNumber,
            value: $value,
            created_by_user_id: $createdByUserId,
            created_at: new DateTimeImmutable(),
        ));
        $this->audit->record(
            action: 'operations.config.version_created',
            subjectType: 'config_version',
            actorUserId: $createdByUserId,
            metadata: [
                'config_key' => $created->config_key,
                'created_version_id' => $created->version_id,
                'version_number' => $created->version_number,
            ],
        );

        return $created;
    }

    private function assertValidKey(string $configKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $configKey) !== 1) {
            throw new InvalidArgumentException('Config key must use dot-separated lowercase identifiers.');
        }

        if (!in_array($configKey, self::supportedConfigKeys(), true)) {
            throw new InvalidArgumentException('Unsupported config key ' . $configKey . '.');
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
            if ($this->isSecretKey($normalized)) {
                throw new InvalidArgumentException('Secret config values must stay in environment secrets.');
            }

            if (is_array($item)) {
                $this->assertNoSecretKeys($item);
            }
        }

        if ($configKey === self::DEFAULT_REVENUE_SHARE_KEY) {
            $this->assertValidDefaultRevenueShare($value);
        }

        if ($configKey === self::RATE_LIMIT_KEY) {
            $this->assertValidRateLimitPolicy($value);
        }

        if ($configKey === self::ATTRIBUTION_DEFAULT_WINDOW_KEY) {
            $this->assertValidAttributionDefaultWindow($value);
        }

        if ($configKey === self::SERVING_EVENT_VALIDATION_KEY) {
            $this->assertValidServingEventValidation($value);
        }

        if ($configKey === self::ASSET_UPLOAD_POLICY_KEY) {
            $this->assertValidAssetUploadPolicy($value);
        }

        if ($configKey === self::AI_REVIEW_POLICY_KEY) {
            $this->assertValidAiReviewPolicy($value);
        }

        if ($configKey === self::WEBHOOK_DELIVERY_POLICY_KEY) {
            $this->assertValidWebhookDeliveryPolicy($value);
        }

        if ($configKey === self::TURNSTILE_POLICY_KEY) {
            $this->assertValidTurnstilePolicy($value);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertNoSecretKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if ($this->isSecretKey($normalized)) {
                throw new InvalidArgumentException('Secret config values must stay in environment secrets.');
            }

            if (is_array($item)) {
                $this->assertNoSecretKeys($item);
            }
        }
    }

    private function isSecretKey(string $normalized): bool
    {
        if (in_array($normalized, ['max_input_tokens', 'max_output_tokens'], true)) {
            return false;
        }

        $compact = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? $normalized;
        if (str_contains($compact, 'password') || str_contains($compact, 'secret') || str_contains($compact, 'token')) {
            return true;
        }

        return str_contains($compact, 'apikey') || str_contains($compact, 'authorization');
    }

    /**
     * @return list<string>
     */
    private static function supportedConfigKeys(): array
    {
        return [
            self::DEFAULT_REVENUE_SHARE_KEY,
            self::RATE_LIMIT_KEY,
            self::ATTRIBUTION_DEFAULT_WINDOW_KEY,
            self::SERVING_EVENT_VALIDATION_KEY,
            self::ASSET_UPLOAD_POLICY_KEY,
            self::AI_REVIEW_POLICY_KEY,
            self::WEBHOOK_DELIVERY_POLICY_KEY,
            self::TURNSTILE_POLICY_KEY,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidDefaultRevenueShare(array $value): void
    {
        $this->assertOnlyFields(self::DEFAULT_REVENUE_SHARE_KEY, $value, ['publisher_percent']);

        $publisherPercent = $value['publisher_percent'] ?? null;
        if (!is_int($publisherPercent) || $publisherPercent < 0 || $publisherPercent > 100) {
            throw new InvalidArgumentException('Invalid billing.default_revenue_share publisher_percent must be an integer between 0 and 100.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidRateLimitPolicy(array $value): void
    {
        $this->assertOnlyFields(self::RATE_LIMIT_KEY, $value, ['limit', 'window_seconds']);

        $this->requiredPositiveInt(self::RATE_LIMIT_KEY, $value, 'limit');
        $this->requiredPositiveInt(self::RATE_LIMIT_KEY, $value, 'window_seconds');
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidAttributionDefaultWindow(array $value): void
    {
        $this->assertOnlyFields(self::ATTRIBUTION_DEFAULT_WINDOW_KEY, $value, ['seconds']);

        $this->requiredPositiveInt(self::ATTRIBUTION_DEFAULT_WINDOW_KEY, $value, 'seconds');
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidServingEventValidation(array $value): void
    {
        $this->assertOnlyFields(
            self::SERVING_EVENT_VALIDATION_KEY,
            $value,
            ['min_visible_ratio', 'min_visible_ms', 'repeat_click_window_seconds'],
        );

        $minVisibleRatio = $value['min_visible_ratio'] ?? null;
        if ((!is_float($minVisibleRatio) && !is_int($minVisibleRatio)) || $minVisibleRatio < 0.0 || $minVisibleRatio > 1.0) {
            throw new InvalidArgumentException('Invalid serving.event_validation min_visible_ratio must be between 0 and 1.');
        }

        $this->requiredPositiveInt(self::SERVING_EVENT_VALIDATION_KEY, $value, 'min_visible_ms');
        $this->requiredPositiveInt(self::SERVING_EVENT_VALIDATION_KEY, $value, 'repeat_click_window_seconds');
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowedFields
     */
    private function assertOnlyFields(string $configKey, array $value, array $allowedFields): void
    {
        $allowed = array_fill_keys($allowedFields, true);
        foreach ($value as $key => $_) {
            if (!isset($allowed[(string) $key])) {
                throw new InvalidArgumentException('Invalid ' . $configKey . ' unknown field ' . (string) $key . '.');
            }
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function requiredPositiveInt(string $configKey, array $value, string $field): int
    {
        $item = $value[$field] ?? null;
        if (!is_int($item) || $item < 1) {
            throw new InvalidArgumentException('Invalid ' . $configKey . ' ' . $field . ' must be an integer >= 1.');
        }

        return $item;
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

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidAiReviewPolicy(array $value): void
    {
        try {
            AiReviewPolicy::fromArray($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid review.ai_policy ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidWebhookDeliveryPolicy(array $value): void
    {
        try {
            WebhookDeliveryPolicy::fromArray($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid webhook.delivery_policy ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function assertValidTurnstilePolicy(array $value): void
    {
        try {
            TurnstilePolicy::fromArray($value);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Invalid security.turnstile_policy ' . $exception->getMessage(), 0, $exception);
        }
    }
}
