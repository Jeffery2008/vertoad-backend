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

        $auditRepository = new ConfigAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));

        $first = $service->createVersion('security.rate_limit', ['limit' => 60, 'window_seconds' => 60], 7);
        $second = $service->createVersion('security.rate_limit', ['limit' => 100, 'window_seconds' => 60], 7);
        $versions = $service->listVersions('security.rate_limit');

        self::assertSame(1, $this->value($first, 'version_number'));
        self::assertSame(2, $this->value($second, 'version_number'));
        self::assertSame('security.rate_limit', $this->value($second, 'config_key'));
        self::assertSame(['limit' => 100, 'window_seconds' => 60], $this->value($second, 'value'));
        self::assertSame(7, $this->value($second, 'created_by_user_id'));
        self::assertCount(2, $versions);
        self::assertCount(2, $auditRepository->entries);
        self::assertSame('operations.config.version_created', $auditRepository->entries[0]->action);
        self::assertSame('config_version', $auditRepository->entries[0]->subjectType);
        self::assertSame(7, $auditRepository->entries[0]->actorUserId);
        self::assertSame('security.rate_limit', $auditRepository->entries[0]->metadata['config_key'] ?? null);
        self::assertSame((string) $this->value($first, 'version_id'), $auditRepository->entries[0]->metadata['created_version_id'] ?? null);
        self::assertSame(1, $auditRepository->entries[0]->metadata['version_number'] ?? null);
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
                ['webhooks.timeout', ['seconds' => 10]],
                ['security.rate_limit', []],
                ['security.rate_limit', ['password' => 'must-not-store-secret']],
                ['security.rate_limit', ['nested' => ['authorization_header' => 'Bearer must-not-store']]],
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

    public function testDefaultRevenueShareConfigVersionAcceptsOnlyStrictDocumentedSchema(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));

        $created = $service->createVersion('billing.default_revenue_share', ['publisher_percent' => 70], 7);

        self::assertSame('billing.default_revenue_share', $this->value($created, 'config_key'));
        self::assertSame(['publisher_percent' => 70], $this->value($created, 'value'));

        foreach (
            [
                'null percent' => ['publisher_percent' => null],
                'negative percent' => ['publisher_percent' => -1],
                'oversized percent' => ['publisher_percent' => 101],
                'string percent' => ['publisher_percent' => '70'],
                'unknown field' => ['publisher_percent' => 70, 'share_ratio_bps' => 7000],
            ] as $case => $value
        ) {
            try {
                $service->createVersion('billing.default_revenue_share', $value, 7);
                self::fail('Invalid billing.default_revenue_share value must be rejected: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith('Invalid billing.default_revenue_share ', $exception->getMessage());
            }
        }
    }

    public function testCoreRuntimeConfigVersionsAcceptOnlyStrictDocumentedSchemas(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));

        foreach (
            [
                'security.rate_limit' => ['limit' => 60, 'window_seconds' => 60],
                'attribution.default_window_seconds' => ['seconds' => 604800],
                'serving.event_validation' => [
                    'min_visible_ratio' => 0.5,
                    'min_visible_ms' => 1000,
                    'repeat_click_window_seconds' => 30,
                ],
            ] as $key => $value
        ) {
            $created = $service->createVersion($key, $value, 7);

            self::assertSame($key, $this->value($created, 'config_key'));
            self::assertSame($value, $this->value($created, 'value'));
        }

        foreach (
            [
                'rate limit unknown field' => [
                    'security.rate_limit',
                    ['limit' => 60, 'window_seconds' => 60, 'unexpected' => true],
                    'Invalid security.rate_limit ',
                ],
                'rate limit non-integer limit' => [
                    'security.rate_limit',
                    ['limit' => '60', 'window_seconds' => 60],
                    'Invalid security.rate_limit ',
                ],
                'rate limit zero window' => [
                    'security.rate_limit',
                    ['limit' => 60, 'window_seconds' => 0],
                    'Invalid security.rate_limit ',
                ],
                'attribution unknown field' => [
                    'attribution.default_window_seconds',
                    ['seconds' => 604800, 'unexpected' => true],
                    'Invalid attribution.default_window_seconds ',
                ],
                'attribution non-integer seconds' => [
                    'attribution.default_window_seconds',
                    ['seconds' => '604800'],
                    'Invalid attribution.default_window_seconds ',
                ],
                'serving ratio outside range' => [
                    'serving.event_validation',
                    [
                        'min_visible_ratio' => 1.01,
                        'min_visible_ms' => 1000,
                        'repeat_click_window_seconds' => 30,
                    ],
                    'Invalid serving.event_validation ',
                ],
                'serving unknown field' => [
                    'serving.event_validation',
                    [
                        'min_visible_ratio' => 0.5,
                        'min_visible_ms' => 1000,
                        'repeat_click_window_seconds' => 30,
                        'unexpected' => true,
                    ],
                    'Invalid serving.event_validation ',
                ],
                'serving non-integer visible milliseconds' => [
                    'serving.event_validation',
                    [
                        'min_visible_ratio' => 0.5,
                        'min_visible_ms' => '1000',
                        'repeat_click_window_seconds' => 30,
                    ],
                    'Invalid serving.event_validation ',
                ],
            ] as $case => [$key, $value, $messagePrefix]
        ) {
            try {
                $service->createVersion($key, $value, 7);
                self::fail('Invalid documented config schema must be rejected: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith($messagePrefix, $exception->getMessage());
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
        $first = $service->createVersion('attribution.default_window_seconds', ['seconds' => 3600], 7);
        $service->createVersion('attribution.default_window_seconds', ['seconds' => 7200], 7);

        $rolledBack = $service->rollback((string) $this->value($first, 'version_id'), 11);

        self::assertSame(3, $this->value($rolledBack, 'version_number'));
        self::assertSame(['seconds' => 3600], $this->value($rolledBack, 'value'));
        self::assertSame(11, $this->value($rolledBack, 'created_by_user_id'));
        self::assertSame([
            'operations.config.version_created',
            'operations.config.version_created',
            'operations.config.version_created',
            'operations.config.rollback',
        ], array_map(static fn (AuditLogEntry $entry): string => $entry->action, $auditRepository->entries));
        $rollbackAudit = $auditRepository->entries[3];
        self::assertSame('operations.config.rollback', $rollbackAudit->action);
        self::assertSame('config_version', $rollbackAudit->subjectType);
        self::assertSame((string) $this->value($first, 'version_id'), $rollbackAudit->metadata['rolled_back_to_version_id'] ?? null);
        self::assertSame((string) $this->value($rolledBack, 'version_id'), $rollbackAudit->metadata['created_version_id'] ?? null);
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

    public function testAiReviewPolicyVersionAcceptsValidNonSecretPolicy(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));

        $created = $service->createVersion('review.ai_policy', $this->validAiReviewPolicyConfig(), 7);

        self::assertSame(1, $this->value($created, 'version_number'));
        self::assertSame('review.ai_policy', $this->value($created, 'config_key'));
        self::assertSame($this->validAiReviewPolicyConfig(), $this->value($created, 'value'));
    }

    public function testAiReviewPolicyVersionsRejectInvalidOrSecretValues(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));
        $valid = $this->validAiReviewPolicyConfig();

        foreach (
            [
                'arbitrary object' => ['enabled' => true],
                'secret api key' => [...$valid, 'api_key' => 'must-stay-in-env'],
                'camel case api key' => [...$valid, 'apiKey' => 'must-stay-in-env'],
                'derived api key name' => [...$valid, 'provider_api_key' => 'must-stay-in-env'],
                'unknown policy field' => [...$valid, 'unexpected' => 'must-not-persist'],
                'disabled wrong type' => [...$valid, 'enabled' => 'true'],
                'unsupported provider' => [...$valid, 'provider' => 'deterministic'],
                'invalid base url' => [...$valid, 'base_url' => 'ftp://ai.example.test/v1'],
                'blank model' => [...$valid, 'model' => ''],
                'blank prompt' => [...$valid, 'prompt' => ''],
                'zero timeout' => [...$valid, 'timeout_seconds' => 0],
                'zero max input' => [...$valid, 'max_input_tokens' => 0],
                'zero max output' => [...$valid, 'max_output_tokens' => 0],
                'temperature above range' => [...$valid, 'temperature' => 2.01],
            ] as $case => $value
        ) {
            try {
                $service->createVersion('review.ai_policy', $value, 7);
                self::fail('Invalid review.ai_policy value must be rejected: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                if ($case === 'secret api key' || $case === 'camel case api key' || $case === 'derived api key name') {
                    self::assertSame('Secret config values must stay in environment secrets.', $exception->getMessage());
                } else {
                    self::assertStringStartsWith('Invalid review.ai_policy ', $exception->getMessage());
                }
            }
        }
    }

    public function testWebhookDeliveryPolicyVersionAcceptsValidNonSecretPolicy(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));

        $created = $service->createVersion('webhook.delivery_policy', $this->validWebhookDeliveryPolicyConfig(), 7);

        self::assertSame(1, $this->value($created, 'version_number'));
        self::assertSame('webhook.delivery_policy', $this->value($created, 'config_key'));
        self::assertSame($this->validWebhookDeliveryPolicyConfig(), $this->value($created, 'value'));
    }

    public function testWebhookDeliveryPolicyVersionsRejectInvalidUnknownOrSecretValues(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));
        $valid = $this->validWebhookDeliveryPolicyConfig();

        foreach (
            [
                'arbitrary object' => ['enabled' => true],
                'secret token' => [...$valid, 'authorization_token' => 'must-stay-in-env'],
                'unknown policy field' => [...$valid, 'unexpected' => true],
                'zero batch size' => [...$valid, 'batch_size' => 0],
                'zero timeout' => [...$valid, 'http_timeout_seconds' => 0],
                'zero retry cap' => [...$valid, 'max_retry_count' => 0],
                'zero backoff' => [...$valid, 'retry_base_backoff_seconds' => 0],
                'oversized batch size' => [...$valid, 'batch_size' => 501],
                'oversized timeout' => [...$valid, 'http_timeout_seconds' => 61],
                'oversized retry cap' => [...$valid, 'max_retry_count' => 21],
                'oversized backoff' => [...$valid, 'retry_base_backoff_seconds' => 86401],
                'string retry cap' => [...$valid, 'max_retry_count' => '3'],
            ] as $case => $value
        ) {
            try {
                $service->createVersion('webhook.delivery_policy', $value, 7);
                self::fail('Invalid webhook.delivery_policy value must be rejected: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                if ($case === 'secret token') {
                    self::assertSame('Secret config values must stay in environment secrets.', $exception->getMessage());
                } else {
                    self::assertStringStartsWith('Invalid webhook.delivery_policy ', $exception->getMessage());
                }
            }
        }
    }

    public function testTurnstilePolicyVersionAcceptsValidNonSecretPolicy(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));

        $created = $service->createVersion('security.turnstile_policy', $this->validTurnstilePolicyConfig(), 7);

        self::assertSame(1, $this->value($created, 'version_number'));
        self::assertSame('security.turnstile_policy', $this->value($created, 'config_key'));
        self::assertSame($this->validTurnstilePolicyConfig(), $this->value($created, 'value'));
    }

    public function testTurnstilePolicyVersionsRejectInvalidUnknownOrSecretValues(): void
    {
        $service = new ConfigVersionService(new InMemoryConfigVersionRepository(), new AuditLogService(new ConfigAuditRepository()));
        $valid = $this->validTurnstilePolicyConfig();

        foreach (
            [
                'missing enabled flag' => [
                    'timeout_seconds' => 5,
                    'protected_endpoints' => ['POST:/api/v1/auth/login'],
                ],
                'secret key' => [...$valid, 'secret_key' => 'must-stay-in-env'],
                'unknown policy field' => [...$valid, 'unexpected' => true],
                'database controlled verify url' => [...$valid, 'verify_url' => 'https://attacker.example.test/siteverify'],
                'zero timeout' => [...$valid, 'timeout_seconds' => 0],
                'oversized timeout' => [...$valid, 'timeout_seconds' => 31],
                'empty endpoints' => [...$valid, 'protected_endpoints' => []],
                'wildcard endpoint' => [...$valid, 'protected_endpoints' => ['*']],
                'map endpoints' => [...$valid, 'protected_endpoints' => ['POST:/api/v1/auth/login' => true]],
                'non-string endpoint' => [...$valid, 'protected_endpoints' => [42]],
                'unsupported method' => [...$valid, 'protected_endpoints' => ['GET:/api/v1/auth/login']],
                'relative endpoint path' => [...$valid, 'protected_endpoints' => ['POST:api/v1/auth/login']],
                'string timeout' => [...$valid, 'timeout_seconds' => '5'],
            ] as $case => $value
        ) {
            try {
                $service->createVersion('security.turnstile_policy', $value, 7);
                self::fail('Invalid security.turnstile_policy value must be rejected: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                if ($case === 'secret key') {
                    self::assertSame('Secret config values must stay in environment secrets.', $exception->getMessage());
                } else {
                    self::assertStringStartsWith('Invalid security.turnstile_policy ', $exception->getMessage());
                }
            }
        }
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

    /**
     * @return array<string, mixed>
     */
    private function validAiReviewPolicyConfig(): array
    {
        return [
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.example/v1',
            'model' => 'review-model',
            'prompt' => 'Return strict JSON with risk_score, risk_labels, reasons, and recommendation.',
            'timeout_seconds' => 60,
            'max_input_tokens' => 12000,
            'max_output_tokens' => 2000,
            'temperature' => 0.2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validWebhookDeliveryPolicyConfig(): array
    {
        return [
            'batch_size' => 50,
            'http_timeout_seconds' => 5,
            'max_retry_count' => 3,
            'retry_base_backoff_seconds' => 300,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validTurnstilePolicyConfig(): array
    {
        return [
            'enabled' => true,
            'timeout_seconds' => 5,
            'protected_endpoints' => [
                'POST:/api/v1/auth/register',
                'POST:/api/v1/auth/login',
                'POST:/api/v1/auth/password-reset/request',
                'POST:/api/v1/auth/password-reset/confirm',
                'POST:/api/v1/billing/recharge-keys/redeem',
                'GET:/api/v1/oauth/authorize',
                'POST:/api/v1/oauth/consent',
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
