<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;
use VertoAD\Repository\Archive\DatabaseArchiveRepository;
use VertoAD\Repository\Attribution\AttributionEventRepositoryInterface;
use VertoAD\Repository\Attribution\DatabaseAttributionEventRepository;
use VertoAD\Repository\Serving\AdCandidateRepositoryInterface;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\DatabaseAdCandidateRepository;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Cron\ServingEventBufferInterface;
use VertoAD\Repository\FeatureFlags\DatabaseFeatureFlagRepository;
use VertoAD\Repository\FeatureFlags\FeatureFlagRepositoryInterface;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\Operations\DatabaseOperationErrorLogRepository;
use VertoAD\Repository\Operations\DatabaseConfigVersionRepository;
use VertoAD\Repository\Operations\ConfigVersionRepositoryInterface;
use VertoAD\Repository\Operations\OperationErrorLogRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Repository\Reporting\ReportAggregateRepositoryInterface;
use VertoAD\Repository\Support\DatabaseSupportTicketRepository;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Repository\UserIdentityRepositoryInterface;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Http\Error\OperationErrorHandler;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveService;
use VertoAD\Service\Archive\ArchiveWriterInterface;
use VertoAD\Service\Archive\ColdQueryRunnerInterface;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DeterministicArchiveWriter;
use VertoAD\Service\Archive\FixtureColdQueryRunner;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Attribution\AttributionService;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\AuthService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\Cron\AiReviewQueueJob;
use VertoAD\Service\Cron\AggregateStatisticsJob;
use VertoAD\Service\Cron\ArchiveParquetJob;
use VertoAD\Service\Cron\BackupCheckJob;
use VertoAD\Service\Cron\CronJobRegistry;
use VertoAD\Service\Cron\CronLockStoreInterface;
use VertoAD\Service\Cron\CronRunner;
use VertoAD\Service\Cron\ConfigCacheRefreshJob;
use VertoAD\Service\Cron\DuckDbColdQueryJob;
use VertoAD\Service\Cron\EventConsumptionJob;
use VertoAD\Service\Cron\ExpiredTokenCleanupJob;
use VertoAD\Service\Cron\FraudFeatureComputeJob;
use VertoAD\Service\Cron\NoOpCronJob;
use VertoAD\Service\PasswordHasher;
use VertoAD\Service\Cron\RedisCronLockStore;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\PublisherSiteVerificationService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\Review\OpenAiCompatibleCreativeReviewProvider;
use VertoAD\Service\Serving\AdSelectionPolicyInterface;
use VertoAD\Service\Serving\AdServingService;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;
use VertoAD\Service\Serving\DatabaseServingRiskAssessor;
use VertoAD\Service\Serving\DefaultAdSelectionPolicy;
use VertoAD\Service\Serving\InMemoryServingFrequencyCapStore;
use VertoAD\Service\Serving\RedisServingFrequencyCapStore;
use VertoAD\Service\Serving\ServingFrequencyCapStoreInterface;
use VertoAD\Service\Serving\ServingRiskAssessorInterface;
use VertoAD\Service\SystemConfigService;
use VertoAD\Service\TenantAccessService;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\RateLimitMiddleware;
use VertoAD\Http\Middleware\TurnstileMiddleware;
use VertoAD\Infrastructure\Security\RateLimiter;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Infrastructure\Security\RateLimitStoreInterface;
use VertoAD\Infrastructure\Security\RedisRateLimitStore;
use VertoAD\Infrastructure\Security\InMemoryRateLimitStore;
use VertoAD\Infrastructure\Security\TurnstileVerifier;

final class AppContainerTest extends TestCase
{
    public function testContainerProvidesDatabaseAndSystemConfigBoundary(): void
    {
        $previousAppKey = getenv('APP_KEY');
        $previousAppEnv = getenv('APP_ENV');
        $previousRedisPassword = getenv('REDIS_PASSWORD');
        $previousRedisDriver = getenv('REDIS_DRIVER');
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=local');
        putenv('REDIS_PASSWORD=');
        putenv('REDIS_DRIVER=auto');

        try {
            $container = AppFactory::create()->getContainer();

            self::assertNotNull($container);
            self::assertInstanceOf(Connection::class, $container->get(Connection::class));
            self::assertInstanceOf(UserIdentityRepositoryInterface::class, $container->get(UserIdentityRepositoryInterface::class));
            self::assertInstanceOf(OrganizationMembershipRepositoryInterface::class, $container->get(OrganizationMembershipRepositoryInterface::class));
            self::assertInstanceOf(PasswordHasher::class, $container->get(PasswordHasher::class));
            self::assertInstanceOf(PasswordResetTokenRepositoryInterface::class, $container->get(PasswordResetTokenRepositoryInterface::class));
            self::assertInstanceOf(FirstPartySessionRepositoryInterface::class, $container->get(FirstPartySessionRepositoryInterface::class));
            self::assertInstanceOf(AuthService::class, $container->get(AuthService::class));
            self::assertInstanceOf(BearerTokenAuthenticator::class, $container->get(BearerTokenAuthenticator::class));
            self::assertInstanceOf(AuthenticateRequestMiddleware::class, $container->get(AuthenticateRequestMiddleware::class));
            self::assertInstanceOf(PermissionMatcher::class, $container->get(PermissionMatcher::class));
            self::assertInstanceOf(TenantAccessService::class, $container->get(TenantAccessService::class));
            self::assertInstanceOf(PublisherSiteRepositoryInterface::class, $container->get(PublisherSiteRepositoryInterface::class));
            self::assertInstanceOf(PublisherSiteVerificationAttemptRepositoryInterface::class, $container->get(PublisherSiteVerificationAttemptRepositoryInterface::class));
            self::assertInstanceOf(PublisherSiteVerificationService::class, $container->get(PublisherSiteVerificationService::class));
            self::assertInstanceOf(AdSlotRepositoryInterface::class, $container->get(AdSlotRepositoryInterface::class));
            self::assertInstanceOf(AdSlotSetupService::class, $container->get(AdSlotSetupService::class));
            self::assertInstanceOf(AuditLogRepositoryInterface::class, $container->get(AuditLogRepositoryInterface::class));
            self::assertInstanceOf(AuditLogService::class, $container->get(AuditLogService::class));
            self::assertInstanceOf(PointsLedgerRepositoryInterface::class, $container->get(PointsLedgerRepositoryInterface::class));
            self::assertInstanceOf(PointsLedgerService::class, $container->get(PointsLedgerService::class));
            self::assertInstanceOf(SupportTicketRepositoryInterface::class, $container->get(SupportTicketRepositoryInterface::class));
            self::assertInstanceOf(DatabaseSupportTicketRepository::class, $container->get(SupportTicketRepositoryInterface::class));
            self::assertInstanceOf(FeatureFlagRepositoryInterface::class, $container->get(FeatureFlagRepositoryInterface::class));
            self::assertInstanceOf(DatabaseFeatureFlagRepository::class, $container->get(FeatureFlagRepositoryInterface::class));

            $cipher = $container->get(RechargeKeyPlaintextCipherInterface::class);
            self::assertInstanceOf(RechargeKeyPlaintextCipherInterface::class, $cipher);
            self::assertSame('rk_container', $cipher->decrypt($cipher->encrypt('rk_container')));

            self::assertInstanceOf(RechargeKeyRepositoryInterface::class, $container->get(RechargeKeyRepositoryInterface::class));
            self::assertInstanceOf(RechargeKeyService::class, $container->get(RechargeKeyService::class));
            self::assertInstanceOf(SystemConfigRepositoryInterface::class, $container->get(SystemConfigRepositoryInterface::class));
            self::assertInstanceOf(SystemConfigService::class, $container->get(SystemConfigService::class));
            self::assertInstanceOf(TurnstileVerifier::class, $container->get(TurnstileVerifier::class));
            self::assertInstanceOf(TurnstileMiddleware::class, $container->get(TurnstileMiddleware::class));
            self::assertInstanceOf(RateLimitStoreInterface::class, $container->get(RateLimitStoreInterface::class));
            self::assertInstanceOf(InMemoryRateLimitStore::class, $container->get(RateLimitStoreInterface::class));
            self::assertInstanceOf(RateLimiter::class, $container->get(RateLimiter::class));
            self::assertInstanceOf(RateLimitPolicy::class, $container->get(RateLimitPolicy::class));
            self::assertInstanceOf(RateLimitMiddleware::class, $container->get(RateLimitMiddleware::class));
            self::assertInstanceOf(AdCandidateRepositoryInterface::class, $container->get(AdCandidateRepositoryInterface::class));
            self::assertInstanceOf(DatabaseAdCandidateRepository::class, $container->get(AdCandidateRepositoryInterface::class));
            self::assertInstanceOf(AdDecisionRepositoryInterface::class, $container->get(AdDecisionRepositoryInterface::class));
            self::assertInstanceOf(DatabaseAdDecisionRepository::class, $container->get(AdDecisionRepositoryInterface::class));
            self::assertInstanceOf(AdEventRepositoryInterface::class, $container->get(AdEventRepositoryInterface::class));
            self::assertInstanceOf(InMemoryAdEventRepository::class, $container->get(AdEventRepositoryInterface::class));
            self::assertInstanceOf(ServingEventBufferInterface::class, $container->get(ServingEventBufferInterface::class));
            self::assertInstanceOf(InMemoryAdEventRepository::class, $container->get(ServingEventBufferInterface::class));
            self::assertInstanceOf(DatabaseAdEventRepository::class, $container->get(DatabaseAdEventRepository::class));
            self::assertInstanceOf(ReportAggregateRepositoryInterface::class, $container->get(ReportAggregateRepositoryInterface::class));
            self::assertInstanceOf(DatabaseReportAggregateRepository::class, $container->get(ReportAggregateRepositoryInterface::class));
            self::assertInstanceOf(DatabaseFraudRiskFeatureRepository::class, $container->get(DatabaseFraudRiskFeatureRepository::class));
            self::assertInstanceOf(ServingFrequencyCapStoreInterface::class, $container->get(ServingFrequencyCapStoreInterface::class));
            self::assertInstanceOf(InMemoryServingFrequencyCapStore::class, $container->get(ServingFrequencyCapStoreInterface::class));
            self::assertInstanceOf(ServingRiskAssessorInterface::class, $container->get(ServingRiskAssessorInterface::class));
            self::assertInstanceOf(DatabaseServingRiskAssessor::class, $container->get(ServingRiskAssessorInterface::class));
            self::assertInstanceOf(AdSelectionPolicyInterface::class, $container->get(AdSelectionPolicyInterface::class));
            self::assertInstanceOf(DefaultAdSelectionPolicy::class, $container->get(AdSelectionPolicyInterface::class));
            self::assertInstanceOf(AttributionEventRepositoryInterface::class, $container->get(AttributionEventRepositoryInterface::class));
            self::assertInstanceOf(DatabaseAttributionEventRepository::class, $container->get(AttributionEventRepositoryInterface::class));
            self::assertInstanceOf(AttributionService::class, $container->get(AttributionService::class));
            self::assertInstanceOf(CampaignSpendEligibilityInterface::class, $container->get(CampaignSpendEligibilityInterface::class));
            self::assertInstanceOf(CampaignBudgetService::class, $container->get(CampaignSpendEligibilityInterface::class));
            self::assertInstanceOf(AdServingService::class, $container->get(AdServingService::class));
            self::assertInstanceOf(ArchiveRepositoryInterface::class, $container->get(ArchiveRepositoryInterface::class));
            self::assertInstanceOf(DatabaseArchiveRepository::class, $container->get(ArchiveRepositoryInterface::class));
            self::assertInstanceOf(ArchiveWriterInterface::class, $container->get(ArchiveWriterInterface::class));
            self::assertInstanceOf(DeterministicArchiveWriter::class, $container->get(ArchiveWriterInterface::class));
            self::assertInstanceOf(ColdQueryRunnerInterface::class, $container->get(ColdQueryRunnerInterface::class));
            self::assertInstanceOf(FixtureColdQueryRunner::class, $container->get(ColdQueryRunnerInterface::class));
            self::assertInstanceOf(ArchiveJob::class, $container->get(ArchiveJob::class));
            self::assertInstanceOf(ArchiveService::class, $container->get(ArchiveService::class));
            self::assertInstanceOf(ColdQueryService::class, $container->get(ColdQueryService::class));
            self::assertInstanceOf(CronLockStoreInterface::class, $container->get(CronLockStoreInterface::class));
            self::assertInstanceOf(EventConsumptionJob::class, $container->get(EventConsumptionJob::class));
            self::assertInstanceOf(WebhookDeliveryRepositoryInterface::class, $container->get(WebhookDeliveryRepositoryInterface::class));
            self::assertInstanceOf(DatabaseWebhookDeliveryRepository::class, $container->get(WebhookDeliveryRepositoryInterface::class));
            self::assertInstanceOf(OperationErrorLogRepositoryInterface::class, $container->get(OperationErrorLogRepositoryInterface::class));
            self::assertInstanceOf(DatabaseOperationErrorLogRepository::class, $container->get(OperationErrorLogRepositoryInterface::class));
            self::assertInstanceOf(ConfigVersionRepositoryInterface::class, $container->get(ConfigVersionRepositoryInterface::class));
            self::assertInstanceOf(DatabaseConfigVersionRepository::class, $container->get(ConfigVersionRepositoryInterface::class));
            self::assertInstanceOf(OperationErrorHandler::class, $container->get(OperationErrorHandler::class));
            self::assertInstanceOf(CronJobRegistry::class, $container->get(CronJobRegistry::class));
            self::assertInstanceOf(WebhookDeliveryJob::class, $container->get(CronJobRegistry::class)->get('webhook-retry'));
            self::assertInstanceOf(ExpiredTokenCleanupJob::class, $container->get(CronJobRegistry::class)->get('expired-token-cleanup'));
            self::assertInstanceOf(AiReviewQueueJob::class, $container->get(CronJobRegistry::class)->get('ai-review-queue'));
            self::assertInstanceOf(AggregateStatisticsJob::class, $container->get(CronJobRegistry::class)->get('aggregate-statistics'));
            self::assertInstanceOf(FraudFeatureComputeJob::class, $container->get(CronJobRegistry::class)->get('fraud-feature-compute'));
            self::assertInstanceOf(ConfigCacheRefreshJob::class, $container->get(CronJobRegistry::class)->get('config-cache-refresh'));
            self::assertInstanceOf(ArchiveParquetJob::class, $container->get(CronJobRegistry::class)->get('archive-parquet'));
            self::assertInstanceOf(DuckDbColdQueryJob::class, $container->get(CronJobRegistry::class)->get('duckdb-cold-query'));
            self::assertInstanceOf(BackupCheckJob::class, $container->get(CronJobRegistry::class)->get('backup-check'));
            self::assertInstanceOf(CronRunner::class, $container->get(CronRunner::class));
        } finally {
            if ($previousAppKey === false) {
                putenv('APP_KEY');
            } else {
                putenv('APP_KEY=' . $previousAppKey);
            }
            if ($previousAppEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previousAppEnv);
            }
            if ($previousRedisPassword === false) {
                putenv('REDIS_PASSWORD');
            } else {
                putenv('REDIS_PASSWORD=' . $previousRedisPassword);
            }
            if ($previousRedisDriver === false) {
                putenv('REDIS_DRIVER');
            } else {
                putenv('REDIS_DRIVER=' . $previousRedisDriver);
            }
        }
    }

    public function testProductionContainerRequiresRedisForServingEventBuffer(): void
    {
        $previousAppKey = getenv('APP_KEY');
        $previousAppEnv = getenv('APP_ENV');
        $previousRedisPassword = getenv('REDIS_PASSWORD');
        $previousRedisDriver = getenv('REDIS_DRIVER');
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=');
        putenv('REDIS_DRIVER=auto');

        try {
            $container = AppFactory::create()->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for serving event buffering.');

            $container?->get(AdEventRepositoryInterface::class);
        } finally {
            if ($previousAppKey === false) {
                putenv('APP_KEY');
            } else {
                putenv('APP_KEY=' . $previousAppKey);
            }
            if ($previousAppEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previousAppEnv);
            }
            if ($previousRedisPassword === false) {
                putenv('REDIS_PASSWORD');
            } else {
                putenv('REDIS_PASSWORD=' . $previousRedisPassword);
            }
            if ($previousRedisDriver === false) {
                putenv('REDIS_DRIVER');
            } else {
                putenv('REDIS_DRIVER=' . $previousRedisDriver);
            }
        }
    }

    public function testProductionContainerRequiresRedisForServingFrequencyCaps(): void
    {
        $previousAppKey = getenv('APP_KEY');
        $previousAppEnv = getenv('APP_ENV');
        $previousRedisPassword = getenv('REDIS_PASSWORD');
        $previousRedisDriver = getenv('REDIS_DRIVER');
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=');
        putenv('REDIS_DRIVER=auto');

        try {
            $container = AppFactory::create()->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for serving frequency caps.');

            $container?->get(ServingFrequencyCapStoreInterface::class);
        } finally {
            if ($previousAppKey === false) {
                putenv('APP_KEY');
            } else {
                putenv('APP_KEY=' . $previousAppKey);
            }
            if ($previousAppEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previousAppEnv);
            }
            if ($previousRedisPassword === false) {
                putenv('REDIS_PASSWORD');
            } else {
                putenv('REDIS_PASSWORD=' . $previousRedisPassword);
            }
            if ($previousRedisDriver === false) {
                putenv('REDIS_DRIVER');
            } else {
                putenv('REDIS_DRIVER=' . $previousRedisDriver);
            }
        }
    }

    public function testContainerUsesRedisServingEventBufferWhenConfigured(): void
    {
        $this->defineFakeRedisIfMissing();

        $previousAppKey = getenv('APP_KEY');
        $previousAppEnv = getenv('APP_ENV');
        $previousRedisPassword = getenv('REDIS_PASSWORD');
        $previousRedisDriver = getenv('REDIS_DRIVER');
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=secret');
        putenv('REDIS_DRIVER=phpredis');

        try {
            $container = AppFactory::create()->getContainer();

            self::assertInstanceOf(InMemoryAdEventRepository::class, $container?->get(InMemoryAdEventRepository::class));
            self::assertNotInstanceOf(InMemoryAdEventRepository::class, $container?->get(AdEventRepositoryInterface::class));
            self::assertInstanceOf(ServingEventBufferInterface::class, $container?->get(AdEventRepositoryInterface::class));
            self::assertInstanceOf(RedisServingFrequencyCapStore::class, $container?->get(ServingFrequencyCapStoreInterface::class));
            self::assertInstanceOf(RedisCronLockStore::class, $container?->get(CronLockStoreInterface::class));
            self::assertInstanceOf(RedisRateLimitStore::class, $container?->get(RateLimitStoreInterface::class));
            self::assertInstanceOf(ConfigCacheRefreshJob::class, $container?->get(ConfigCacheRefreshJob::class));
        } finally {
            if ($previousAppKey === false) {
                putenv('APP_KEY');
            } else {
                putenv('APP_KEY=' . $previousAppKey);
            }
            if ($previousAppEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $previousAppEnv);
            }
            if ($previousRedisPassword === false) {
                putenv('REDIS_PASSWORD');
            } else {
                putenv('REDIS_PASSWORD=' . $previousRedisPassword);
            }
            if ($previousRedisDriver === false) {
                putenv('REDIS_DRIVER');
            } else {
                putenv('REDIS_DRIVER=' . $previousRedisDriver);
            }
        }
    }

    #[RunInSeparateProcess]
    public function testLocalContainerFallsBackToMemoryWhenRedisPasswordIsMissing(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('The Redis extension is available in this process.');
        }

        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=local');
        putenv('REDIS_PASSWORD=');
        putenv('REDIS_DRIVER=auto');

        $container = AppFactory::create()->getContainer();

        self::assertInstanceOf(InMemoryAdEventRepository::class, $container?->get(AdEventRepositoryInterface::class));
    }

    #[RunInSeparateProcess]
    public function testProductionContainerReportsExplicitPhpRedisDriverWithoutRedisExtension(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('The Redis extension is available in this process.');
        }

        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=secret');
        putenv('REDIS_DRIVER=phpredis');

        $container = AppFactory::create()->getContainer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Redis extension is required for phpredis connections.');

        $container?->get(AdEventRepositoryInterface::class);
    }

    #[RunInSeparateProcess]
    public function testProductionConfigCacheRefreshRequiresRedisPassword(): void
    {
        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('APP_ENV=prod');
        putenv('REDIS_PASSWORD=');

        $container = AppFactory::create()->getContainer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REDIS_PASSWORD is required for config cache refresh.');

        $container?->get(ConfigCacheRefreshJob::class);
    }

    public function testAggregateStatisticsLookbackMustBePositive(): void
    {
        $basePath = sys_get_temp_dir() . '/vertoad-appfactory-aggregate-' . bin2hex(random_bytes(4));
        $configPath = $basePath . '/config';
        mkdir($configPath, recursive: true);
        file_put_contents($configPath . '/settings.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'app' => [
        'debug' => false,
        'key' => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
    ],
    'database' => [
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ],
    'cron' => [
        'token' => '',
        'allowed_ips' => [],
        'aggregate_statistics_lookback_hours' => 0,
        'jobs' => ['aggregate-statistics'],
    ],
];
PHP);
        file_put_contents($configPath . '/routes.php', <<<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
};
PHP);

        try {
            $container = AppFactory::create($basePath)->getContainer();

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CRON_AGGREGATE_STATISTICS_LOOKBACK_HOURS must be positive.');

            $container?->get(AggregateStatisticsJob::class);
        } finally {
            @unlink($configPath . '/routes.php');
            @unlink($configPath . '/settings.php');
            @rmdir($configPath);
            @rmdir($basePath);
        }
    }

    public function testFraudFeatureLookbackMustBePositive(): void
    {
        $basePath = sys_get_temp_dir() . '/vertoad-appfactory-fraud-' . bin2hex(random_bytes(4));
        $configPath = $basePath . '/config';
        mkdir($configPath, recursive: true);
        file_put_contents($configPath . '/settings.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'app' => [
        'debug' => false,
        'key' => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
    ],
    'database' => [
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ],
    'cron' => [
        'token' => '',
        'allowed_ips' => [],
        'fraud_feature_lookback_hours' => 0,
        'jobs' => ['fraud-feature-compute'],
    ],
];
PHP);
        file_put_contents($configPath . '/routes.php', <<<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
};
PHP);

        try {
            $container = AppFactory::create($basePath)->getContainer();

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('CRON_FRAUD_FEATURE_LOOKBACK_HOURS must be positive.');

            $container?->get(FraudFeatureComputeJob::class);
        } finally {
            @unlink($configPath . '/routes.php');
            @unlink($configPath . '/settings.php');
            @rmdir($configPath);
            @rmdir($basePath);
        }
    }

    public function testUnknownConfiguredCronJobFallsBackToNoOp(): void
    {
        $basePath = sys_get_temp_dir() . '/vertoad-appfactory-noop-' . bin2hex(random_bytes(4));
        $configPath = $basePath . '/config';
        mkdir($configPath, recursive: true);
        file_put_contents($configPath . '/settings.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'app' => [
        'debug' => false,
        'key' => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
    ],
    'database' => [
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ],
    'cron' => [
        'token' => '',
        'allowed_ips' => [],
        'jobs' => ['legacy-custom-job'],
    ],
];
PHP);
        file_put_contents($configPath . '/routes.php', <<<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
};
PHP);

        try {
            $container = AppFactory::create($basePath)->getContainer();

            self::assertInstanceOf(NoOpCronJob::class, $container?->get(CronJobRegistry::class)->get('legacy-custom-job'));
        } finally {
            @unlink($configPath . '/routes.php');
            @unlink($configPath . '/settings.php');
            @rmdir($configPath);
            @rmdir($basePath);
        }
    }

    public function testWebhookDeliveryJobUsesConfiguredRetryCapAndBackoff(): void
    {
        $basePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => ['webhook-retry'],
            ],
            'webhooks' => [
                'signing_secret' => 'whsec_container_test',
                'retry_batch_size' => 9,
                'http_timeout_seconds' => 2,
                'max_retry_count' => 6,
                'retry_base_backoff_seconds' => 45,
            ],
        ], 'vertoad-appfactory-webhooks-');

        try {
            $container = AppFactory::create($basePath)->getContainer();
            $job = $container?->get(WebhookDeliveryJob::class);

            self::assertInstanceOf(WebhookDeliveryJob::class, $job);
            self::assertSame(9, $this->privateIntProperty($job, 'batchSize'));
            self::assertSame(6, $this->privateIntProperty($job, 'maxRetryCount'));
            self::assertSame(45, $this->privateIntProperty($job, 'baseBackoffSeconds'));
        } finally {
            $this->removeTemporaryAppBasePath($basePath);
        }
    }

    public function testRuntimePoliciesUseVersionedSystemConfigInsteadOfSettingsFallbacks(): void
    {
        $databasePath = sys_get_temp_dir() . '/vertoad-system-config-' . bin2hex(random_bytes(4)) . '.sqlite';
        $basePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'env' => 'prod',
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'path' => $databasePath,
            ],
            'redis' => [
                'driver' => 'predis',
                'password' => 'unit-test-redis-secret',
                'prefix' => 'vertoad:test:',
            ],
            'storage' => [
                's3' => [
                    'endpoint' => 'https://r2.example.test',
                    'bucket' => 'creative-assets',
                    'access_key_id' => 'access-key',
                    'secret_access_key' => 'secret-key',
                    'path_style_endpoint' => true,
                ],
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
            'security' => [
                'rate_limit' => [
                    'limit' => 999,
                    'window_seconds' => 999,
                ],
            ],
            'attribution' => [
                'default_window_seconds' => 999,
            ],
        ], 'vertoad-appfactory-system-config-');

        try {
            $connection = \Doctrine\DBAL\DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $databasePath,
            ]);
            $this->createSystemConfigSchema($connection);
            $this->insertSystemConfig($connection, 'security.rate_limit', 1, ['limit' => 30, 'window_seconds' => 10]);
            $this->insertSystemConfig($connection, 'security.rate_limit', 2, ['limit' => 7, 'window_seconds' => 3]);
            $this->insertSystemConfig($connection, 'attribution.default_window_seconds', 1, ['seconds' => 3600]);
            $this->insertSystemConfig($connection, 'serving.event_validation', 1, [
                'min_visible_ratio' => 0.75,
                'min_visible_ms' => 1500,
                'repeat_click_window_seconds' => 45,
            ]);
            $this->insertSystemConfig($connection, 'assets.upload_policy', 1, $this->assetUploadPolicyConfig([
                'upload_intent_ttl_seconds' => 120,
                'image_max_bytes' => 2048,
                'image_max_width' => 512,
                'image_max_height' => 512,
            ]));

            $container = AppFactory::create($basePath)->getContainer();
            $policy = $container?->get(RateLimitPolicy::class);
            $attribution = $container?->get(AttributionService::class);
            $serving = $container?->get(AdServingService::class);
            $assetUploads = $container?->get(AssetUploadService::class);

            self::assertInstanceOf(RateLimitPolicy::class, $policy);
            self::assertSame(7, $policy->limit);
            self::assertSame(3, $policy->windowSeconds);
            self::assertInstanceOf(AttributionService::class, $attribution);
            self::assertSame(3600, $this->privateIntProperty($attribution, 'defaultWindowSeconds'));
            self::assertInstanceOf(AdServingService::class, $serving);
            $eventPolicy = $this->privateObjectProperty($serving, 'eventPolicy');
            self::assertSame(0.75, $eventPolicy->minVisibleRatio);
            self::assertSame(1500, $eventPolicy->minVisibleMs);
            self::assertSame(45, $eventPolicy->repeatClickWindowSeconds);
            self::assertInstanceOf(AssetUploadService::class, $assetUploads);
            $assetPolicy = $this->privateObjectProperty($assetUploads, 'policy');
            self::assertSame(120, $assetPolicy->uploadIntentTtlSeconds);
            self::assertSame(2048, $assetPolicy->maxBytes(AssetType::Image));
            self::assertSame(512, $assetPolicy->maxWidth(AssetType::Image));
            self::assertSame(512, $assetPolicy->maxHeight(AssetType::Image));
        } finally {
            $this->removeTemporaryAppBasePath($basePath);
            @unlink($databasePath);
        }
    }

    public function testProductionRuntimeConfigResolutionFailsWhenRequiredSystemConfigIsMissing(): void
    {
        $databasePath = sys_get_temp_dir() . '/vertoad-system-config-missing-' . bin2hex(random_bytes(4)) . '.sqlite';
        $basePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'env' => 'prod',
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'path' => $databasePath,
            ],
            'redis' => [
                'driver' => 'predis',
                'password' => 'unit-test-redis-secret',
                'prefix' => 'vertoad:test:',
            ],
            'storage' => [
                's3' => [
                    'endpoint' => 'https://r2.example.test',
                    'bucket' => 'creative-assets',
                    'access_key_id' => 'access-key',
                    'secret_access_key' => 'secret-key',
                    'path_style_endpoint' => true,
                ],
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
        ], 'vertoad-appfactory-system-config-missing-');

        try {
            $connection = \Doctrine\DBAL\DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $databasePath,
            ]);
            $this->createSystemConfigSchema($connection);

            $container = AppFactory::create($basePath)->getContainer();

            try {
                $container?->get(RateLimitPolicy::class);
                self::fail('Production rate limit policy must require versioned system config.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Missing required system config: security.rate_limit.', $exception->getMessage());
            }

            try {
                $container?->get(AttributionService::class);
                self::fail('Production attribution service must require versioned system config.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Missing required system config: attribution.default_window_seconds.',
                    $exception->getMessage(),
                );
            }

            try {
                $container?->get(AdServingService::class);
                self::fail('Production ad serving service must require versioned system config.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Missing required system config: serving.event_validation.', $exception->getMessage());
            }

            try {
                $container?->get(AssetUploadService::class);
                self::fail('Production asset upload service must require versioned system config.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Missing required system config: assets.upload_policy.', $exception->getMessage());
            }
        } finally {
            $this->removeTemporaryAppBasePath($basePath);
            @unlink($databasePath);
        }
    }

    public function testProductionRequiresExplicitArchiveRuntimeAdapters(): void
    {
        $basePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'env' => 'prod',
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
            'archive' => [
                'raw_events_base_object_key' => 's3://vertoad-archive/raw-events',
                'query_results_base_object_key' => 's3://vertoad-archive/query-results',
            ],
        ]);

        try {
            $container = AppFactory::create($basePath)->getContainer();

            try {
                $container?->get(ArchiveWriterInterface::class);
                self::fail('Expected production archive writer resolution to require an explicit adapter.');
            } catch (\RuntimeException $exception) {
                self::assertSame('ARCHIVE_WRITER must be configured outside local/testing.', $exception->getMessage());
            }

            try {
                $container?->get(ColdQueryRunnerInterface::class);
                self::fail('Expected production cold query runner resolution to require an explicit adapter.');
            } catch (\RuntimeException $exception) {
                self::assertSame('ARCHIVE_COLD_QUERY_RUNNER must be configured outside local/testing.', $exception->getMessage());
            }
        } finally {
            $this->removeTemporaryAppBasePath($basePath);
        }
    }

    public function testArchiveRuntimeAdaptersAreExplicitlyLimitedToLocalAndTesting(): void
    {
        $localBasePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'env' => 'testing',
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
            'archive' => [
                'writer' => 'deterministic',
                'cold_query_runner' => 'fixture',
            ],
        ]);
        $prodBasePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'env' => 'prod',
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
            'archive' => [
                'writer' => 'deterministic',
                'cold_query_runner' => 'fixture',
            ],
        ]);

        try {
            $localContainer = AppFactory::create($localBasePath)->getContainer();
            self::assertInstanceOf(DeterministicArchiveWriter::class, $localContainer?->get(ArchiveWriterInterface::class));
            self::assertInstanceOf(FixtureColdQueryRunner::class, $localContainer?->get(ColdQueryRunnerInterface::class));

            $prodContainer = AppFactory::create($prodBasePath)->getContainer();
            try {
                $prodContainer?->get(ArchiveWriterInterface::class);
                self::fail('Expected deterministic archive writer to be forbidden outside local/testing.');
            } catch (\RuntimeException $exception) {
                self::assertSame('ARCHIVE_WRITER=deterministic is only allowed in local/testing.', $exception->getMessage());
            }

            try {
                $prodContainer?->get(ColdQueryRunnerInterface::class);
                self::fail('Expected fixture cold query runner to be forbidden outside local/testing.');
            } catch (\RuntimeException $exception) {
                self::assertSame('ARCHIVE_COLD_QUERY_RUNNER=fixture is only allowed in local/testing.', $exception->getMessage());
            }
        } finally {
            $this->removeTemporaryAppBasePath($localBasePath);
            $this->removeTemporaryAppBasePath($prodBasePath);
        }
    }

    public function testCreateLoadsEnvironmentFileWhenPresentInBasePath(): void
    {
        $basePath = sys_get_temp_dir() . '/vertoad-appfactory-env-' . bin2hex(random_bytes(4));
        $configPath = $basePath . '/config';
        mkdir($configPath, recursive: true);
        file_put_contents($basePath . '/.env', "VERTOAD_APPFACTORY_ENV_BRANCH=loaded\n");
        file_put_contents($configPath . '/settings.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'app' => [
        'debug' => false,
        'key' => \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(),
    ],
    'database' => [
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ],
    'cron' => [
        'token' => '',
        'allowed_ips' => [],
        'jobs' => [],
    ],
];
PHP);
        file_put_contents($configPath . '/routes.php', <<<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
};
PHP);

        unset($_ENV['VERTOAD_APPFACTORY_ENV_BRANCH'], $_SERVER['VERTOAD_APPFACTORY_ENV_BRANCH']);
        putenv('VERTOAD_APPFACTORY_ENV_BRANCH');

        try {
            AppFactory::create($basePath);

            self::assertSame('loaded', $_ENV['VERTOAD_APPFACTORY_ENV_BRANCH'] ?? null);
            self::assertSame('loaded', getenv('VERTOAD_APPFACTORY_ENV_BRANCH'));
        } finally {
            unset($_ENV['VERTOAD_APPFACTORY_ENV_BRANCH'], $_SERVER['VERTOAD_APPFACTORY_ENV_BRANCH']);
            putenv('VERTOAD_APPFACTORY_ENV_BRANCH');
            @unlink($configPath . '/routes.php');
            @unlink($configPath . '/settings.php');
            @unlink($basePath . '/.env');
            @rmdir($configPath);
            @rmdir($basePath);
        }
    }

    public function testContainerSelectsAiReviewProviderFromConfigurationCompleteness(): void
    {
        $basePaths = [];
        try {
            $basePaths[] = $this->temporaryAppBasePath([
                'base_url' => '',
                'api_key' => '',
                'model' => 'deterministic-from-config',
            ]);
            $fallbackContainer = AppFactory::create($basePaths[0])->getContainer();
            self::assertInstanceOf(
                DeterministicCreativeReviewProvider::class,
                $fallbackContainer?->get(CreativeReviewProviderInterface::class),
            );

            $basePaths[] = $this->temporaryAppBasePath([
                'base_url' => 'https://ai.example.test/v1',
                'api_key' => 'unit-test-ai-review-key',
                'model' => 'review-model',
                'prompt' => 'Return JSON.',
            ]);
            $openAiContainer = AppFactory::create($basePaths[1])->getContainer();
            self::assertInstanceOf(
                OpenAiCompatibleCreativeReviewProvider::class,
                $openAiContainer?->get(CreativeReviewProviderInterface::class),
            );
        } finally {
            foreach ($basePaths as $basePath) {
                @unlink($basePath . '/config/routes.php');
                @unlink($basePath . '/config/settings.php');
                @rmdir($basePath . '/config');
                @rmdir($basePath);
            }
        }
    }

    public function testAiReviewProviderRequiresCompleteConfigurationOutsideLocalTesting(): void
    {
        $basePath = $this->temporaryAppBasePathWithSettings([
            'app' => [
                'env' => 'prod',
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
            'ai_review' => [
                'base_url' => 'https://ai.example.test/v1',
                'api_key' => '',
                'model' => 'review-model',
            ],
        ], 'vertoad-appfactory-ai-prod-');

        try {
            $container = AppFactory::create($basePath)->getContainer();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('AI_REVIEW_BASE_URL, AI_REVIEW_API_KEY, and AI_REVIEW_MODEL are required outside local/testing.');

            $container?->get(CreativeReviewProviderInterface::class);
        } finally {
            @unlink($basePath . '/config/routes.php');
            @unlink($basePath . '/config/settings.php');
            @rmdir($basePath . '/config');
            @rmdir($basePath);
        }
    }

    /** @param array<string, mixed> $aiReview */
    private function temporaryAppBasePath(array $aiReview): string
    {
        return $this->temporaryAppBasePathWithSettings([
            'app' => [
                'debug' => false,
                'key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            ],
            'database' => [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ],
            'cron' => [
                'token' => '',
                'allowed_ips' => [],
                'jobs' => [],
            ],
            'ai_review' => $aiReview,
        ], 'vertoad-appfactory-ai-');
    }

    /** @param array<string, mixed> $settings */
    private function temporaryAppBasePathWithSettings(array $settings, string $prefix = 'vertoad-appfactory-'): string
    {
        $basePath = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
        $configPath = $basePath . '/config';
        mkdir($configPath, recursive: true);
        file_put_contents($configPath . '/settings.php', '<?php return ' . var_export($settings, true) . ';');
        file_put_contents($configPath . '/routes.php', <<<'PHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app): void {
};
PHP);

        return $basePath;
    }

    private function removeTemporaryAppBasePath(string $basePath): void
    {
        @unlink($basePath . '/config/routes.php');
        @unlink($basePath . '/config/settings.php');
        @rmdir($basePath . '/config');
        @rmdir($basePath);
    }

    private function privateIntProperty(object $object, string $property): int
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return (int) $reflection->getValue($object);
    }

    private function privateObjectProperty(object $object, string $property): object
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function createSystemConfigSchema(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE system_config_versions (
                version_id VARCHAR(80) PRIMARY KEY,
                config_key VARCHAR(160) NOT NULL,
                version INTEGER NOT NULL,
                value_json TEXT NOT NULL,
                created_by_user_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private function insertSystemConfig(Connection $connection, string $key, int $version, array $value): void
    {
        $connection->insert('system_config_versions', [
            'version_id' => 'cfgv_' . sha1($key . ':' . $version),
            'config_key' => $key,
            'version' => $version,
            'value_json' => json_encode($value, JSON_THROW_ON_ERROR),
            'created_by_user_id' => 1,
            'created_at' => '2026-06-12 00:00:00',
        ]);
    }

    /**
     * @param array{upload_intent_ttl_seconds?:int, image_max_bytes?:int, image_max_width?:int, image_max_height?:int} $overrides
     * @return array<string, mixed>
     */
    private function assetUploadPolicyConfig(array $overrides = []): array
    {
        return [
            'upload_intent_ttl_seconds' => $overrides['upload_intent_ttl_seconds'] ?? 900,
            'blocked_extensions' => ['html', 'htm', 'js', 'mjs', 'svg'],
            'blocked_content_types' => ['text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'],
            'types' => [
                'image' => [
                    'max_bytes' => $overrides['image_max_bytes'] ?? 10_485_760,
                    'max_width' => $overrides['image_max_width'] ?? 4096,
                    'max_height' => $overrides['image_max_height'] ?? 4096,
                    'allowed_content_types' => [
                        'png' => 'image/png',
                        'jpg' => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp',
                    ],
                    'magic_signatures' => [
                        'image/png' => [['prefix_base64' => base64_encode("\x89PNG\r\n\x1A\n")]],
                        'image/jpeg' => [['prefix_base64' => base64_encode("\xFF\xD8\xFF")]],
                        'image/gif' => [
                            ['prefix_ascii' => 'GIF87a'],
                            ['prefix_ascii' => 'GIF89a'],
                        ],
                        'image/webp' => [
                            ['prefix_ascii' => 'RIFF', 'offset_ascii' => ['offset' => 8, 'value' => 'WEBP']],
                        ],
                    ],
                ],
                'video' => [
                    'max_bytes' => 209_715_200,
                    'max_width' => 3840,
                    'max_height' => 2160,
                    'max_duration_seconds' => 120.0,
                    'allowed_content_types' => [
                        'mp4' => 'video/mp4',
                        'webm' => 'video/webm',
                    ],
                    'magic_signatures' => [
                        'video/mp4' => [['offset_ascii' => ['offset' => 4, 'value' => 'ftyp']]],
                        'video/webm' => [['prefix_base64' => base64_encode("\x1A\x45\xDF\xA3")]],
                    ],
                ],
                'fabric_snapshot' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['json' => 'application/json'],
                    'magic_signatures' => [
                        'application/json' => [
                            ['trimmed_prefix_ascii' => '{'],
                            ['trimmed_prefix_ascii' => '['],
                        ],
                    ],
                ],
                'text' => [
                    'max_bytes' => 1_048_576,
                    'allowed_content_types' => ['txt' => 'text/plain'],
                    'magic_signatures' => [
                        'text/plain' => [['forbid_ascii_ci' => '<script']],
                    ],
                ],
            ],
        ];
    }

    private function defineFakeRedisIfMissing(): void
    {
        if (class_exists(\Redis::class)) {
            return;
        }

        eval(<<<'PHP'
final class Redis
{
    public array $keys = [];
    public array $values = [];
    public array $counts = [];
    public array $ttl = [];
    public array $zsets = [];
    public array $connections = [];
    public ?string $password = null;
    public ?int $database = null;

    public function connect(string $host, int $port, float $timeout): bool
    {
        $this->connections[] = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

        return true;
    }

    public function auth(string $password): bool
    {
        $this->password = $password;

        return true;
    }

    public function select(int $database): bool
    {
        $this->database = $database;

        return true;
    }

    public function incr(string $key): int
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key];
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->ttl[$key] = $seconds;

        return true;
    }

    public function set(string $key, string $value, array $options = []): bool
    {
        if (isset($options[0]) && $options[0] === 'nx' && isset($this->keys[$key])) {
            return false;
        }

        $this->keys[$key] = (int) ($options['ex'] ?? 0);
        $this->values[$key] = $value;

        return true;
    }

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function exists(string $key): int
    {
        return isset($this->keys[$key]) ? 1 : 0;
    }

    public function del(string $key): int
    {
        if (!isset($this->keys[$key])) {
            return 0;
        }

        unset($this->keys[$key], $this->values[$key], $this->ttl[$key]);

        return 1;
    }

    public function zAdd(string $key, float $score, string $member): int
    {
        $exists = isset($this->zsets[$key][$member]);
        $this->zsets[$key][$member] = $score;

        return $exists ? 0 : 1;
    }

    public function zRem(string $key, string $member): int
    {
        if (!isset($this->zsets[$key][$member])) {
            return 0;
        }

        unset($this->zsets[$key][$member]);

        return 1;
    }

    public function zRangeByScore(string $key, string $from, string $to, array $options = []): array
    {
        $fromScore = $from === '-inf' ? -INF : (float) $from;
        $toScore = $to === '+inf' ? INF : (float) $to;
        $members = [];
        foreach ($this->sortedZset($key) as $member => $score) {
            if ($score >= $fromScore && $score <= $toScore) {
                $members[] = $member;
            }
        }

        if (isset($options['limit'])) {
            return array_slice($members, $options['limit'][0], $options['limit'][1]);
        }

        return $members;
    }

    public function zRange(string $key, int $start, int $end): array
    {
        $members = array_keys($this->sortedZset($key));
        $length = $end < 0 ? null : $end - $start + 1;

        return array_slice($members, $start, $length);
    }

    public function eval(string $script, array $args, int $numKeys): array
    {
        if (str_contains($script, "redis.call('GET', KEYS[1])") && $numKeys === 1) {
            $key = (string) $args[0];
            $expectedValue = (string) ($args[1] ?? '');
            if (($this->values[$key] ?? null) === $expectedValue) {
                $this->del($key);

                return [1];
            }

            return [0];
        }

        $pending = $args[0];
        $processing = $args[1];
        $now = (float) $args[2];
        $limit = (int) $args[3];
        $deadline = (float) $args[4];

        foreach ($this->zRangeByScore($processing, '-inf', (string) $now) as $member) {
            $this->zRem($processing, $member);
            $this->zAdd($pending, $now, $member);
        }

        $claimed = [];
        foreach ($this->zRange($pending, 0, $limit - 1) as $member) {
            if ($this->zRem($pending, $member) === 1) {
                $this->zAdd($processing, $deadline, $member);
                $claimed[] = $member;
            }
        }

        return $claimed;
    }

    private function sortedZset(string $key): array
    {
        $members = $this->zsets[$key] ?? [];
        asort($members, SORT_NUMERIC);

        return $members;
    }
}
PHP);
    }
}
