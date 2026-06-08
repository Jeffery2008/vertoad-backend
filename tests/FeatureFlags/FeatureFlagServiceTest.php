<?php

declare(strict_types=1);

namespace VertoAD\Tests\FeatureFlags;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Audit\AuditLogEntry;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Service\AuditLogService;

final class FeatureFlagServiceTest extends TestCase
{
    public function testCreatesUpdatesPublishesAndEvaluatesTargetedFeatureFlags(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\FeatureFlags\\InMemoryFeatureFlagRepository';
        $serviceClass = 'VertoAD\\Service\\FeatureFlags\\FeatureFlagService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $auditRepository = new FeatureFlagAuditRepository();
        $service = new $serviceClass(new $repositoryClass(), new AuditLogService($auditRepository));

        $flag = $service->publish($service->createOrUpdate([
            'flag_key' => 'ai_review.fast_path',
            'environment' => 'staging',
            'enabled' => true,
            'targets' => [
                'organization_ids' => [101],
                'user_ids' => [501],
                'roles' => ['advertiser'],
                'site_ids' => [301],
                'slot_ids' => [401],
            ],
            'percentage_rollout' => 25,
            'time_window' => [
                'starts_at' => '2026-06-08T00:00:00+00:00',
                'ends_at' => '2026-06-30T23:59:59+00:00',
            ],
        ], 700), 700);

        foreach (['flag_key', 'environment', 'enabled', 'targets', 'percentage_rollout', 'time_window', 'created_at', 'updated_at'] as $field) {
            self::assertArrayHasKey($field, $this->record($flag), $field . ' must be present.');
        }

        $enabled = $service->evaluate('ai_review.fast_path', [
            'environment' => 'staging',
            'organization_id' => 101,
            'user_id' => 501,
            'roles' => ['advertiser'],
            'site_id' => 301,
            'slot_id' => 401,
            'now' => '2026-06-09T00:00:00+00:00',
            'rollout_seed' => 'org-101-user-501',
        ]);
        $disabled = $service->evaluate('ai_review.fast_path', [
            'environment' => 'prod',
            'organization_id' => 101,
            'user_id' => 501,
            'roles' => ['advertiser'],
            'now' => '2026-06-09T00:00:00+00:00',
        ]);

        self::assertSame(true, $this->value($enabled, 'enabled'));
        self::assertSame('target_match', $this->value($enabled, 'reason'));
        self::assertSame(false, $this->value($disabled, 'enabled'));
        self::assertSame('environment_mismatch', $this->value($disabled, 'reason'));
        self::assertSame('feature_flag.updated', $auditRepository->entries[0]->action ?? null);
        self::assertSame('feature_flag.published', $auditRepository->entries[1]->action ?? null);
    }

    public function testEvaluationExplainsDisabledReasonsForOffWindowAndRolloutMiss(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\FeatureFlags\\InMemoryFeatureFlagRepository';
        $serviceClass = 'VertoAD\\Service\\FeatureFlags\\FeatureFlagService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new FeatureFlagAuditRepository()));
        $service->publish($service->createOrUpdate([
            'flag_key' => 'sdk.lazy_loader',
            'environment' => 'prod',
            'enabled' => true,
            'targets' => ['organization_ids' => [101]],
            'percentage_rollout' => 0,
            'time_window' => [
                'starts_at' => '2026-06-10T00:00:00+00:00',
                'ends_at' => '2026-06-30T00:00:00+00:00',
            ],
        ], 700), 700);

        $outsideWindow = $service->evaluate('sdk.lazy_loader', [
            'environment' => 'prod',
            'organization_id' => 101,
            'now' => '2026-06-09T00:00:00+00:00',
        ]);
        $rolloutMiss = $service->evaluate('sdk.lazy_loader', [
            'environment' => 'prod',
            'organization_id' => 101,
            'now' => '2026-06-11T00:00:00+00:00',
            'rollout_seed' => 'org-101',
        ]);

        self::assertSame(false, $this->value($outsideWindow, 'enabled'));
        self::assertSame('outside_time_window', $this->value($outsideWindow, 'reason'));
        self::assertSame(false, $this->value($rolloutMiss, 'enabled'));
        self::assertSame('rollout_miss', $this->value($rolloutMiss, 'reason'));
    }

    public function testRejectsInvalidPercentageTimeWindowAndFlagKey(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\FeatureFlags\\InMemoryFeatureFlagRepository';
        $serviceClass = 'VertoAD\\Service\\FeatureFlags\\FeatureFlagService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new FeatureFlagAuditRepository()));

        foreach (
            [
                ['flag_key' => '../secret', 'percentage_rollout' => 10, 'time_window' => null],
                ['flag_key' => 'sdk.lazy_loader', 'percentage_rollout' => -1, 'time_window' => null],
                ['flag_key' => 'sdk.lazy_loader', 'percentage_rollout' => 101, 'time_window' => null],
                [
                    'flag_key' => 'sdk.lazy_loader',
                    'percentage_rollout' => 10,
                    'time_window' => [
                        'starts_at' => '2026-06-30T00:00:00+00:00',
                        'ends_at' => '2026-06-10T00:00:00+00:00',
                    ],
                ],
            ] as $invalid
        ) {
            try {
                $service->createOrUpdate(array_replace([
                    'environment' => 'prod',
                    'enabled' => true,
                    'targets' => [],
                ], $invalid), 700);
                self::fail('Invalid flag payload must throw.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPublishRejectsMissingFlagAndEvaluationCoversGlobalRolloutAndRoleTargets(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\FeatureFlags\\InMemoryFeatureFlagRepository';
        $serviceClass = 'VertoAD\\Service\\FeatureFlags\\FeatureFlagService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new FeatureFlagAuditRepository()));

        try {
            $service->publish(['flag_key' => 'missing.flag'], 700);
            self::fail('Publishing a missing feature flag must fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Feature flag not found.', $exception->getMessage());
        }

        $service->publish($service->createOrUpdate([
            'flag_key' => 'global.rollout',
            'environment' => 'prod',
            'enabled' => true,
            'targets' => [],
            'percentage_rollout' => 100,
            'time_window' => null,
        ], 700), 700);
        $global = $service->evaluate('global.rollout', [
            'environment' => 'prod',
            'rollout_seed' => 'any',
        ]);
        self::assertTrue($this->value($global, 'enabled'));
        self::assertSame('target_match', $this->value($global, 'reason'));

        $service->publish($service->createOrUpdate([
            'flag_key' => 'role.target',
            'environment' => 'prod',
            'enabled' => true,
            'targets' => ['roles' => ['support']],
            'percentage_rollout' => 25,
            'time_window' => null,
        ], 700), 700);
        $role = $service->evaluate('role.target', [
            'environment' => 'prod',
            'roles' => ['support'],
            'rollout_seed' => 'support-user',
        ]);
        self::assertTrue($this->value($role, 'enabled'));

        $service->publish($service->createOrUpdate([
            'flag_key' => 'partial.rollout',
            'environment' => 'prod',
            'enabled' => true,
            'targets' => [],
            'percentage_rollout' => 50,
            'time_window' => null,
        ], 700), 700);
        $partial = $service->evaluate('partial.rollout', [
            'environment' => 'prod',
            'rollout_seed' => 'seed',
        ]);
        self::assertTrue($this->value($partial, 'enabled'));
    }

    public function testRejectsInvalidEnvironmentTargetsAndMalformedTimeWindowShapes(): void
    {
        $repositoryClass = 'VertoAD\\Repository\\FeatureFlags\\InMemoryFeatureFlagRepository';
        $serviceClass = 'VertoAD\\Service\\FeatureFlags\\FeatureFlagService';
        self::assertTrue(class_exists($repositoryClass), $repositoryClass . ' must exist.');
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(new $repositoryClass(), new AuditLogService(new FeatureFlagAuditRepository()));

        foreach (
            [
                ['environment' => 'qa', 'targets' => [], 'time_window' => null],
                ['environment' => 'prod', 'targets' => 'bad', 'time_window' => null],
                ['environment' => 'prod', 'targets' => ['bad_target' => [1]], 'time_window' => null],
                ['environment' => 'prod', 'targets' => ['organization_ids' => 'bad'], 'time_window' => null],
                ['environment' => 'prod', 'targets' => [], 'time_window' => 'bad'],
                ['environment' => 'prod', 'targets' => [], 'time_window' => ['starts_at' => '2026-06-08T00:00:00+00:00']],
            ] as $invalid
        ) {
            try {
                $service->createOrUpdate(array_replace([
                    'flag_key' => 'invalid.shape',
                    'enabled' => true,
                    'percentage_rollout' => 10,
                ], $invalid), 700);
                self::fail('Invalid feature flag shape must fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function record(mixed $record): array
    {
        if (is_array($record)) {
            return $record;
        }

        return get_object_vars($record);
    }

    private function value(mixed $record, string $key): mixed
    {
        return $this->record($record)[$key] ?? null;
    }
}

final class FeatureFlagAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditLogEntry> */
    public array $entries = [];

    public function append(AuditLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
