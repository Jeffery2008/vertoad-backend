<?php

declare(strict_types=1);

namespace VertoAD;

use VertoAD\Domain\Billing\WithdrawalProofPolicy;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Action\Cron\CronRunAction;
use VertoAD\Http\Action\HealthAction;
use VertoAD\Http\Action\Install\InstallAction;
use VertoAD\Http\Action\Install\InstalledInstallAction;
use VertoAD\Http\Action\Serving\ServeAction;
use VertoAD\Http\Action\Serving\ServeFrameAction;
use VertoAD\Http\Error\OperationErrorHandler;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\CronAuthMiddleware;
use VertoAD\Http\Middleware\CorsMiddleware;
use VertoAD\Http\Middleware\CreativeTemplateWritePermissionMiddleware;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\IpGeoMiddleware;
use VertoAD\Http\Middleware\LazyContainerMiddleware;
use VertoAD\Http\Middleware\RateLimitMiddleware;
use VertoAD\Http\Middleware\RequestIdMiddleware;
use VertoAD\Http\Middleware\TurnstileMiddleware;
use VertoAD\Http\Middleware\WebhookRequestIdMiddleware;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Infrastructure\OAuth\LeagueOAuthRepository;
use VertoAD\Infrastructure\OAuth\LeagueOAuthServerFactory;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Infrastructure\Security\InMemoryRateLimitStore;
use VertoAD\Infrastructure\Security\RateLimiter;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Infrastructure\Security\RateLimitStoreInterface;
use VertoAD\Infrastructure\Security\RedisRateLimitStore;
use VertoAD\Infrastructure\Security\TurnstileVerifier;
use VertoAD\Infrastructure\Storage\AwsS3PresignedUploadSigner;
use VertoAD\Infrastructure\Redis\InMemoryRedisClient;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\ObjectStorageInspectorInterface;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Infrastructure\Storage\PublicUrlObjectStorageInspector;
use VertoAD\Infrastructure\Storage\S3ArchiveObjectStorage;
use VertoAD\Infrastructure\Storage\S3AssetObjectStorage;
use VertoAD\Infrastructure\Storage\S3BackupObjectStorage;
use VertoAD\Infrastructure\Storage\S3ObjectStorageInspector;
use VertoAD\Infrastructure\Storage\UnavailableAssetObjectStorage;
use VertoAD\Infrastructure\Storage\UnavailableBackupObjectStorage;
use VertoAD\Infrastructure\Storage\UnavailableObjectStorageInspector;
use VertoAD\Install\BootstrapSeeder;
use VertoAD\Install\InstallFilesystem;
use VertoAD\Install\InstallerService;
use VertoAD\Install\InstallSecretGenerator;
use VertoAD\Install\InstallSecurity;
use VertoAD\Install\InstallState;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Repository\AdSlotRepository;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;
use VertoAD\Repository\Archive\DatabaseArchiveRepository;
use VertoAD\Repository\AuditLogRepository;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Assets\AssetRepository;
use VertoAD\Repository\Assets\AssetRepositoryInterface;
use VertoAD\Repository\Assets\AssetSnapshotJobRepository;
use VertoAD\Repository\Assets\AssetSnapshotJobRepositoryInterface;
use VertoAD\Repository\Attribution\AttributionEventRepositoryInterface;
use VertoAD\Repository\Attribution\DatabaseAttributionEventRepository;
use VertoAD\Repository\Billing\CpmBillingRepositoryInterface;
use VertoAD\Repository\Billing\DatabaseCpmBillingRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\Campaign\CampaignRepository;
use VertoAD\Repository\Campaign\CampaignRepositoryInterface;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
use VertoAD\Repository\Creative\CreativeDesignRepositoryInterface;
use VertoAD\Repository\Creative\CreativeTemplateRepositoryInterface;
use VertoAD\Repository\Creative\DatabaseCreativeDesignRepository;
use VertoAD\Repository\Creative\DatabaseCreativeTemplateRepository;
use VertoAD\Repository\FeatureFlags\DatabaseFeatureFlagRepository;
use VertoAD\Repository\FeatureFlags\FeatureFlagRepositoryInterface;
use VertoAD\Repository\Serving\AdCandidateRepositoryInterface;
use VertoAD\Repository\Serving\AdDecisionRepositoryInterface;
use VertoAD\Repository\Serving\AdEventRepositoryInterface;
use VertoAD\Repository\Serving\DatabaseAdCandidateRepository;
use VertoAD\Repository\Serving\DatabaseAdDecisionRepository;
use VertoAD\Repository\Serving\DatabaseAdEventRepository;
use VertoAD\Repository\Serving\DatabaseServingInventoryRepository;
use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
use VertoAD\Repository\Serving\InMemoryAdEventRepository;
use VertoAD\Repository\Serving\RedisAdEventRepository;
use VertoAD\Repository\Serving\ServingInventoryRepositoryInterface;
use VertoAD\Repository\Cron\ServingEventBufferInterface;
use VertoAD\Repository\Cron\ServingRequestEventBufferInterface;
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;
use VertoAD\Repository\IpGeo\DatabaseIpGeoRepository;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Repository\IpGeo\RedisIpGeoRepository;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepository;
use VertoAD\Repository\OAuthConsentRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\Operations\ConfigVersionRepositoryInterface;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;
use VertoAD\Repository\Operations\DatabaseBackupJobRepository;
use VertoAD\Repository\Operations\DatabaseConfigVersionRepository;
use VertoAD\Repository\Operations\DatabaseOperationErrorLogRepository;
use VertoAD\Repository\Operations\DatabaseOperationRiskDecisionLogRepository;
use VertoAD\Repository\Operations\DatabaseOperationSystemLogRepository;
use VertoAD\Repository\Operations\OperationErrorLogRepositoryInterface;
use VertoAD\Repository\Operations\OperationRiskDecisionLogRepositoryInterface;
use VertoAD\Repository\Operations\OperationSystemLogRepositoryInterface;
use VertoAD\Repository\OrganizationMemberManagementRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepository;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepository;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepository;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Repository\Reporting\DatabaseConversionPathRepository;
use VertoAD\Repository\Reporting\InMemoryReportAggregateRepository;
use VertoAD\Repository\Reporting\ReportAggregateRepositoryInterface;
use VertoAD\Repository\Reporting\ConversionPathRepositoryInterface;
use VertoAD\Repository\SystemConfigRepository;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Repository\Support\DatabaseSupportTicketRepository;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Repository\UserIdentityRepository;
use VertoAD\Repository\UserIdentityRepositoryInterface;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookOutboxRepository;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEventDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookOutboxRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Assets\AssetObjectStorageInterface;
use VertoAD\Service\Assets\AssetPublicUrlResolver;
use VertoAD\Service\Assets\AssetSnapshotGenerator;
use VertoAD\Service\Assets\FabricCreativePayloadValidator;
use VertoAD\Service\Assets\FfmpegAssetFrameExtractor;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveObjectStorageInterface;
use VertoAD\Service\Archive\ArchiveService;
use VertoAD\Service\Archive\ArchiveWriterInterface;
use VertoAD\Service\Archive\ColdQueryRunnerInterface;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DeterministicArchiveWriter;
use VertoAD\Service\Archive\DuckDbCliArchiveWriter;
use VertoAD\Service\Archive\DuckDbCliColdQueryRunner;
use VertoAD\Service\Archive\FixtureColdQueryRunner;
use VertoAD\Service\Cron\ArchiveParquetJob;
use VertoAD\Service\Cron\AiReviewQueueJob;
use VertoAD\Service\Cron\AggregateStatisticsJob;
use VertoAD\Service\Cron\AssetSnapshotGenerationJob;
use VertoAD\Service\Cron\BackupCheckJob;
use VertoAD\Service\Cron\BackupCreateJob;
use VertoAD\Service\Cron\BackupRestoreJob;
use VertoAD\Service\Attribution\AttributionService;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\AuthService;
use VertoAD\Service\Billing\CpmBillingService;
use VertoAD\Service\Billing\RevenueShareService;
use VertoAD\Service\Billing\AdEventBillingService;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\Campaign\CampaignService;
use VertoAD\Service\CampaignBudgetService;
use VertoAD\Service\Cron\CronJobInterface;
use VertoAD\Service\Cron\CronJobRegistry;
use VertoAD\Service\Cron\CronLockStoreInterface;
use VertoAD\Service\Cron\CronRunner;
use VertoAD\Service\Cron\ConfigCacheRefreshJob;
use VertoAD\Service\Cron\DuckDbColdQueryJob;
use VertoAD\Service\Cron\EventConsumptionJob;
use VertoAD\Service\Cron\ExpiredTokenCleanupJob;
use VertoAD\Service\Cron\FraudFeatureComputeJob;
use VertoAD\Service\Cron\InMemoryCronLockStore;
use VertoAD\Service\Cron\IpGeoLookupJob;
use VertoAD\Service\Cron\NoOpCronJob;
use VertoAD\Service\Cron\PartitionMaintenanceJob;
use VertoAD\Service\Cron\RedisCronLockStore;
use VertoAD\Service\Creative\CreativeDesignService;
use VertoAD\Service\DefuseRechargeKeyPlaintextCipher;
use VertoAD\Service\FeatureFlags\FeatureFlagService;
use VertoAD\Service\IpGeo\AsyncIpGeoResolver;
use VertoAD\Service\IpGeo\DisabledIpGeoRequestContextResolver;
use VertoAD\Service\IpGeo\IpGeoRequestContextResolverInterface;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedHttpIpGeoProviderClient;
use VertoAD\Service\IpGeo\MappedIpGeoResponseNormalizer;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthScopeCatalog;
use VertoAD\Service\OAuthTokenService;
use VertoAD\Service\Operations\ConfigVersionService;
use VertoAD\Service\Operations\Backup\BackupExecutor;
use VertoAD\Service\Operations\Backup\BackupInventory;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;
use VertoAD\Service\Operations\Backup\BackupSourceRegistry;
use VertoAD\Service\Operations\Backup\BackupService;
use VertoAD\Service\Operations\Backup\MysqlBackupRunnerInterface;
use VertoAD\Service\Operations\Backup\ProcessMysqlBackupRunner;
use VertoAD\Service\Operations\Backup\UnavailableMysqlBackupRunner;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\Operations\OperationRequestCorrelationService;
use VertoAD\Service\Operations\OperationsSummaryService;
use VertoAD\Service\Operations\RealTimeGeoLookupInterface;
use VertoAD\Service\Operations\RepositoryRealTimeGeoLookup;
use VertoAD\Service\Partition\EventTablePartitionMaintainer;
use VertoAD\Service\Serving\AdServingService;
use VertoAD\Service\Serving\AdSelectionPolicyInterface;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;
use VertoAD\Service\Serving\DatabaseServingRiskAssessor;
use VertoAD\Service\Serving\DefaultAdSelectionPolicy;
use VertoAD\Service\Serving\GeoResolverInterface;
use VertoAD\Service\Serving\InMemoryServingFrequencyCapStore;
use VertoAD\Service\Serving\NullGeoResolver;
use VertoAD\Service\Serving\RedisServingFrequencyCapStore;
use VertoAD\Service\Serving\ServingFrequencyCapStoreInterface;
use VertoAD\Service\Serving\ServingRiskAssessorInterface;
use VertoAD\Service\PasswordHasher;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\Publisher\PublisherIntegrationCodeService;
use VertoAD\Service\PublisherSiteVerificationService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\Review\OpenAiCompatibleCreativeReviewProvider;
use VertoAD\Service\Reporting\ReportQueryService;
use VertoAD\Service\Reporting\ConversionPathReportService;
use VertoAD\Service\ReviewService;
use VertoAD\Service\RuntimeConfigHealthCheck;
use VertoAD\Service\SystemConfigService;
use VertoAD\Service\Support\SupportTicketService;
use VertoAD\Service\TenantAccessService;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipher;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;
use VertoAD\Service\Webhooks\WebhookOutboxDispatchService;
use VertoAD\Service\Webhooks\WebhookSigner;

final class AppFactory
{
    public static function create(?string $basePath = null): App
    {
        $rootPath = $basePath ?? dirname(__DIR__);

        $environmentPath = EnvironmentLoader::load($rootPath);

        $settings = require $rootPath . '/config/settings.php';
        $installState = new InstallState($rootPath);
        if (!$installState->isInstalled()) {
            return self::createInstallerApp($rootPath, $environmentPath, $settings, $installState);
        }

        $container = (new ContainerBuilder())
            ->addDefinitions([
                'settings' => $settings,
                Connection::class => static fn (): Connection => (new ConnectionFactory())->create($settings['database']),
                UserIdentityRepositoryInterface::class => static fn (Connection $connection): UserIdentityRepositoryInterface =>
                    new UserIdentityRepository($connection),
                OrganizationMembershipRepositoryInterface::class => static fn (Connection $connection): OrganizationMembershipRepositoryInterface =>
                    new OrganizationMembershipRepository($connection),
                OrganizationMemberManagementRepositoryInterface::class => static fn (Connection $connection): OrganizationMemberManagementRepositoryInterface =>
                    new OrganizationMembershipRepository($connection),
                PasswordHasher::class => static fn (): PasswordHasher => new PasswordHasher(),
                PasswordResetTokenRepositoryInterface::class => static fn (Connection $connection): PasswordResetTokenRepositoryInterface =>
                    new PasswordResetTokenRepository($connection),
                FirstPartySessionRepositoryInterface::class => static fn (Connection $connection): FirstPartySessionRepositoryInterface =>
                    new FirstPartySessionRepository($connection),
                OAuthClientRepositoryInterface::class => static fn (Connection $connection): OAuthClientRepositoryInterface =>
                    new OAuthClientRepository($connection),
                OAuthTokenRepositoryInterface::class => static fn (Connection $connection): OAuthTokenRepositoryInterface =>
                    new OAuthTokenRepository($connection),
                OAuthConsentRepositoryInterface::class => static fn (Connection $connection): OAuthConsentRepositoryInterface =>
                    new OAuthConsentRepository($connection),
                OAuthClientSecretHasher::class => static fn (): OAuthClientSecretHasher => new OAuthClientSecretHasher(),
                LeagueOAuthRepository::class => static fn (
                    OAuthClientRepositoryInterface $clients,
                    OAuthTokenRepositoryInterface $tokens,
                    OAuthClientSecretHasher $secrets,
                ): LeagueOAuthRepository => new LeagueOAuthRepository($clients, $tokens, $secrets),
                LeagueOAuthServerFactory::class => static fn (
                    LeagueOAuthRepository $repository,
                ): LeagueOAuthServerFactory => new LeagueOAuthServerFactory($repository, $settings['oauth'] ?? []),
                OAuthTokenService::class => static fn (
                    OAuthClientRepositoryInterface $clients,
                    OAuthTokenRepositoryInterface $tokens,
                    OAuthConsentRepositoryInterface $consents,
                    OAuthClientSecretHasher $secrets,
                ): OAuthTokenService => new OAuthTokenService($clients, $tokens, $consents, $secrets),
                AuthService::class => static fn (
                    Connection $connection,
                    PasswordHasher $passwordHasher,
                    PasswordResetTokenRepositoryInterface $resetTokens,
                    FirstPartySessionRepositoryInterface $sessions,
                ): AuthService => new AuthService($connection, $passwordHasher, $resetTokens, $sessions),
                BearerTokenAuthenticator::class => static fn (
                    FirstPartySessionRepositoryInterface $sessions,
                    OAuthTokenRepositoryInterface $oauthTokens,
                ): BearerTokenAuthenticator => new BearerTokenAuthenticator($sessions, $oauthTokens),
                AuthenticateRequestMiddleware::class => static fn (
                    BearerTokenAuthenticator $authenticator,
                ): AuthenticateRequestMiddleware => new AuthenticateRequestMiddleware($authenticator),
                PermissionMatcher::class => static fn (): PermissionMatcher => new PermissionMatcher(),
                OAuthScopeCatalog::class => static fn (
                    OrganizationMembershipRepositoryInterface $memberships,
                    PermissionMatcher $permissions,
                ): OAuthScopeCatalog => new OAuthScopeCatalog($memberships, $permissions),
                TenantAccessService::class => static fn (
                    OrganizationMembershipRepositoryInterface $memberships,
                    PermissionMatcher $permissions,
                ): TenantAccessService => new TenantAccessService($memberships, $permissions),
                CreativeTemplateWritePermissionMiddleware::class => static fn (
                    TenantAccessService $tenantAccess,
                ): CreativeTemplateWritePermissionMiddleware => new CreativeTemplateWritePermissionMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $tenantAccess,
                ),
                PublisherSiteRepositoryInterface::class => static fn (Connection $connection): PublisherSiteRepositoryInterface =>
                    new PublisherSiteRepository($connection),
                PublisherSiteVerificationAttemptRepositoryInterface::class => static fn (Connection $connection): PublisherSiteVerificationAttemptRepositoryInterface =>
                    new PublisherSiteVerificationAttemptRepository($connection),
                PublisherSiteVerificationService::class => static fn (
                    PublisherSiteRepositoryInterface $repository,
                    PublisherSiteVerificationAttemptRepositoryInterface $attempts,
                ): PublisherSiteVerificationService => new PublisherSiteVerificationService($repository, $attempts),
                AdSlotRepositoryInterface::class => static fn (Connection $connection): AdSlotRepositoryInterface =>
                    new AdSlotRepository($connection),
                AdSlotSetupService::class => static fn (
                    PublisherSiteRepositoryInterface $sites,
                    AdSlotRepositoryInterface $slots,
                ): AdSlotSetupService => new AdSlotSetupService($sites, $slots),
                PublisherIntegrationCodeService::class => static fn (
                    PublisherSiteRepositoryInterface $sites,
                    AdSlotRepositoryInterface $slots,
                ): PublisherIntegrationCodeService => new PublisherIntegrationCodeService(
                    $sites,
                    $slots,
                    self::integrationPublicBaseUrl('SDK_PUBLIC_BASE_URL', $settings),
                    self::integrationPublicBaseUrl('ADS_PUBLIC_BASE_URL', $settings),
                ),
                AssetRepositoryInterface::class => static fn (Connection $connection): AssetRepositoryInterface =>
                    new AssetRepository($connection),
                AssetSnapshotJobRepositoryInterface::class => static fn (Connection $connection): AssetSnapshotJobRepositoryInterface =>
                    new AssetSnapshotJobRepository($connection),
                ObjectStorageUploadSignerInterface::class => static fn (): ObjectStorageUploadSignerInterface =>
                    self::objectStorageUploadSigner($settings),
                ObjectStorageInspectorInterface::class => static fn (): ObjectStorageInspectorInterface =>
                    self::objectStorageInspector($settings),
                AssetPublicUrlResolver::class => static fn (): AssetPublicUrlResolver =>
                    self::assetPublicUrlResolver($settings),
                AssetObjectStorageInterface::class => static fn (): AssetObjectStorageInterface =>
                    self::assetObjectStorage($settings),
                FabricCreativePayloadValidator::class => static fn (
                    AssetPublicUrlResolver $publicUrls,
                ): FabricCreativePayloadValidator => new FabricCreativePayloadValidator($publicUrls),
                FfmpegAssetFrameExtractor::class => static fn (): FfmpegAssetFrameExtractor =>
                    new FfmpegAssetFrameExtractor(
                        binary: (string) ($settings['cron']['asset_snapshot_ffmpeg_binary'] ?? 'ffmpeg'),
                        timeoutSeconds: (int) ($settings['cron']['asset_snapshot_ffmpeg_timeout_seconds'] ?? 30),
                    ),
                AssetSnapshotGenerator::class => static fn (
                    FabricCreativePayloadValidator $fabricValidator,
                    FfmpegAssetFrameExtractor $videoFrames,
                ): AssetSnapshotGenerator => new AssetSnapshotGenerator($fabricValidator, $videoFrames),
                AssetUploadService::class => static fn (
                    AssetRepositoryInterface $repository,
                    ObjectStorageUploadSignerInterface $signer,
                    ObjectStorageInspectorInterface $inspector,
                    SystemConfigService $configs,
                    FabricCreativePayloadValidator $fabricValidator,
                ): AssetUploadService => new AssetUploadService(
                    $repository,
                    $signer,
                    $inspector,
                    $configs->assetUploadPolicy(),
                    fabricValidator: $fabricValidator,
                ),
                ReviewRepositoryInterface::class => static fn (Connection $connection): ReviewRepositoryInterface =>
                    new ReviewRepository($connection),
                CreativeReviewProviderInterface::class => static fn (
                    SystemConfigService $configs,
                ): CreativeReviewProviderInterface =>
                    self::creativeReviewProvider($settings, $configs),
                ReviewService::class => static fn (
                    ReviewRepositoryInterface $repository,
                    CreativeReviewProviderInterface $provider,
                ): ReviewService => new ReviewService($repository, $provider),
                CampaignRepositoryInterface::class => static fn (Connection $connection): CampaignRepositoryInterface =>
                    new CampaignRepository($connection),
                CampaignBudgetRepositoryInterface::class => static fn (Connection $connection): CampaignBudgetRepositoryInterface =>
                    new CampaignBudgetRepository($connection),
                CampaignBudgetService::class => static fn (
                    CampaignBudgetRepositoryInterface $budgets,
                    PointsLedgerService $ledger,
                    PointsLedgerRepositoryInterface $ledgerRepository,
                    CampaignRepositoryInterface $campaigns,
                ): CampaignBudgetService => new CampaignBudgetService($budgets, $ledger, $ledgerRepository, $campaigns),
                CampaignSpendEligibilityInterface::class => static fn (CampaignBudgetService $budgets): CampaignSpendEligibilityInterface =>
                    $budgets,
                CampaignService::class => static fn (
                    CampaignRepositoryInterface $campaigns,
                    ReviewRepositoryInterface $reviews,
                    CampaignBudgetService $budgets,
                ): CampaignService => new CampaignService($campaigns, $reviews, $budgets),
                CreativeTemplateRepositoryInterface::class => static fn (Connection $connection): CreativeTemplateRepositoryInterface =>
                    new DatabaseCreativeTemplateRepository($connection),
                CreativeDesignRepositoryInterface::class => static fn (Connection $connection): CreativeDesignRepositoryInterface =>
                    new DatabaseCreativeDesignRepository($connection),
                CreativeDesignService::class => static fn (
                    CreativeTemplateRepositoryInterface $templates,
                    CreativeDesignRepositoryInterface $designs,
                    AuditLogService $audit,
                ): CreativeDesignService => new CreativeDesignService($templates, $designs, $audit),
                ServingInventoryRepositoryInterface::class => static fn (Connection $connection): ServingInventoryRepositoryInterface =>
                    new DatabaseServingInventoryRepository($connection),
                AdCandidateRepositoryInterface::class => static fn (
                    Connection $connection,
                    AssetPublicUrlResolver $publicUrls,
                ): AdCandidateRepositoryInterface => new DatabaseAdCandidateRepository($connection, $publicUrls),
                AdDecisionRepositoryInterface::class => static fn (Connection $connection): AdDecisionRepositoryInterface =>
                    new DatabaseAdDecisionRepository($connection),
                DatabaseAdEventRepository::class => static fn (Connection $connection): DatabaseAdEventRepository =>
                    new DatabaseAdEventRepository($connection),
                RedisAdEventRepository::class => static fn (): RedisAdEventRepository =>
                    RedisAdEventRepository::fromSettings($settings['redis'] ?? []),
                InMemoryAdEventRepository::class => static fn (): InMemoryAdEventRepository =>
                    new InMemoryAdEventRepository(),
                AdEventRepositoryInterface::class => static fn () : AdEventRepositoryInterface =>
                    self::servingEventRepository($settings),
                ServingEventBufferInterface::class => static fn (AdEventRepositoryInterface $repository): ServingEventBufferInterface =>
                    $repository,
                ReportAggregateRepositoryInterface::class => static fn (Connection $connection): ReportAggregateRepositoryInterface =>
                    new DatabaseReportAggregateRepository($connection),
                ConversionPathRepositoryInterface::class => static fn (Connection $connection): ConversionPathRepositoryInterface =>
                    new DatabaseConversionPathRepository($connection),
                DatabaseFraudRiskFeatureRepository::class => static fn (Connection $connection): DatabaseFraudRiskFeatureRepository =>
                    new DatabaseFraudRiskFeatureRepository($connection),
                ServingFrequencyCapStoreInterface::class => static fn (): ServingFrequencyCapStoreInterface =>
                    self::servingFrequencyCapStore($settings),
                ServingRiskAssessorInterface::class => static fn (
                    DatabaseFraudRiskFeatureRepository $features,
                ): ServingRiskAssessorInterface => new DatabaseServingRiskAssessor($features),
                AdSelectionPolicyInterface::class => static fn (
                    ServingFrequencyCapStoreInterface $frequencyCaps,
                    ServingRiskAssessorInterface $riskAssessor,
                ): AdSelectionPolicyInterface => new DefaultAdSelectionPolicy($frequencyCaps, $riskAssessor),
                IpGeoRepositoryInterface::class => static fn (
                    Connection $connection,
                    IpGeoProviderPolicy $policy,
                ): IpGeoRepositoryInterface => self::ipGeoRepository($settings, $connection, $policy),
                IpGeoProviderPolicy::class => static fn (SystemConfigService $configs): IpGeoProviderPolicy =>
                    $configs->ipGeoProviderPolicy(),
                IpGeoProviderSelector::class => static fn (IpGeoProviderPolicy $policy): IpGeoProviderSelector =>
                    new IpGeoProviderSelector($policy),
                MappedIpGeoResponseNormalizer::class => static fn (): MappedIpGeoResponseNormalizer =>
                    new MappedIpGeoResponseNormalizer(),
                MappedHttpIpGeoProviderClient::class => static fn (
                    MappedIpGeoResponseNormalizer $normalizer,
                ): MappedHttpIpGeoProviderClient => new MappedHttpIpGeoProviderClient($normalizer),
                GeoResolverInterface::class => static fn (
                    IpGeoRepositoryInterface $repository,
                    IpGeoProviderPolicy $policy,
                ): GeoResolverInterface => self::geoResolver($repository, $policy),
                IpGeoRequestContextResolverInterface::class => static fn (
                    IpGeoRepositoryInterface $repository,
                    IpGeoProviderPolicy $policy,
                ): IpGeoRequestContextResolverInterface => self::ipGeoRequestContextResolver($repository, $policy),
                IpGeoMiddleware::class => static fn (
                    ClientIpResolver $ipResolver,
                    IpGeoRequestContextResolverInterface $geoResolver,
                ): IpGeoMiddleware => new IpGeoMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $ipResolver,
                    $geoResolver,
                ),
                ServeAction::class => static fn (
                    AdServingService $serving,
                    ClientIpResolver $ipResolver,
                    GeoResolverInterface $geoResolver,
                ): ServeAction => new ServeAction($serving, $ipResolver, $geoResolver),
                ServeFrameAction::class => static fn (
                    AdServingService $serving,
                    ClientIpResolver $ipResolver,
                    GeoResolverInterface $geoResolver,
                    AssetPublicUrlResolver $publicAssets,
                ): ServeFrameAction => new ServeFrameAction($serving, $ipResolver, $geoResolver, $publicAssets),
                ReportQueryService::class => static fn (
                    ReportAggregateRepositoryInterface $aggregates,
                ): ReportQueryService => new ReportQueryService($aggregates),
                ConversionPathReportService::class => static fn (
                    ConversionPathRepositoryInterface $paths,
                ): ConversionPathReportService => new ConversionPathReportService($paths),
                AttributionEventRepositoryInterface::class => static fn (Connection $connection): AttributionEventRepositoryInterface =>
                    new DatabaseAttributionEventRepository($connection),
                AttributionService::class => static fn (
                    AttributionEventRepositoryInterface $events,
                    SystemConfigService $configs,
                ): AttributionService => new AttributionService(
                    $events,
                    $configs->attributionDefaultWindowSeconds(),
                ),
                ArchiveRepositoryInterface::class => static fn (Connection $connection): ArchiveRepositoryInterface =>
                    new DatabaseArchiveRepository($connection),
                ArchiveObjectStorageInterface::class => static fn (): ArchiveObjectStorageInterface =>
                    self::archiveObjectStorage($settings),
                ArchiveWriterInterface::class => static fn (): ArchiveWriterInterface =>
                    self::archiveWriter($settings),
                ColdQueryRunnerInterface::class => static fn (): ColdQueryRunnerInterface =>
                    self::coldQueryRunner($settings),
                ArchiveJob::class => static fn (
                    ArchiveRepositoryInterface $repository,
                    ArchiveWriterInterface $writer,
                ): ArchiveJob => new ArchiveJob(
                    $repository,
                    (string) ($settings['archive']['raw_events_base_object_key'] ?? 's3://vertoad-archive/raw-events'),
                    $writer,
                ),
                ArchiveService::class => static fn (
                    ArchiveRepositoryInterface $repository,
                ): ArchiveService => new ArchiveService($repository),
                ColdQueryService::class => static fn (
                    ArchiveRepositoryInterface $repository,
                    ColdQueryRunnerInterface $runner,
                ): ColdQueryService => new ColdQueryService(
                    $repository,
                    (string) ($settings['archive']['query_results_base_object_key'] ?? 's3://vertoad-archive/query-results'),
                    $runner,
                ),
                OperationErrorLogRepositoryInterface::class => static fn (Connection $connection): OperationErrorLogRepositoryInterface =>
                    new DatabaseOperationErrorLogRepository($connection),
                OperationSystemLogRepositoryInterface::class => static fn (Connection $connection): OperationSystemLogRepositoryInterface =>
                    new DatabaseOperationSystemLogRepository($connection),
                OperationRiskDecisionLogRepositoryInterface::class => static fn (Connection $connection): OperationRiskDecisionLogRepositoryInterface =>
                    new DatabaseOperationRiskDecisionLogRepository($connection),
                OperationErrorCaptureService::class => static fn (
                    OperationErrorLogRepositoryInterface $errors,
                    AuditLogService $audit,
                    OperationSystemLogRepositoryInterface $systemLogs,
                ): OperationErrorCaptureService => new OperationErrorCaptureService($errors, $audit, $systemLogs),
                OperationRequestCorrelationService::class => static fn (
                    OperationErrorCaptureService $errors,
                    AuditLogService $auditLogs,
                    WebhookDeliveryRepositoryInterface $webhooks,
                    IpGeoRepositoryInterface $ipGeoRepository,
                    AdDecisionRepositoryInterface $servingDecisions,
                    AdEventRepositoryInterface $servingEvents,
                    DatabaseAdEventRepository $servingEventHistory,
                    OperationSystemLogRepositoryInterface $systemLogs,
                    OperationRiskDecisionLogRepositoryInterface $riskDecisions,
                ): OperationRequestCorrelationService => new OperationRequestCorrelationService(
                    $errors,
                    $auditLogs,
                    $webhooks,
                    $ipGeoRepository,
                    $servingDecisions,
                    $servingEvents,
                    $servingEventHistory,
                    $systemLogs,
                    $riskDecisions,
                ),
                OperationErrorHandler::class => static fn (
                    OperationErrorCaptureService $errors,
                    ClientIpResolver $ipResolver,
                ): OperationErrorHandler => new OperationErrorHandler(SlimAppFactory::determineResponseFactory(), $errors, $ipResolver),
                ConfigVersionRepositoryInterface::class => static fn (Connection $connection): ConfigVersionRepositoryInterface =>
                    new DatabaseConfigVersionRepository($connection),
                ConfigVersionService::class => static fn (
                    ConfigVersionRepositoryInterface $versions,
                    AuditLogService $audit,
                ): ConfigVersionService => new ConfigVersionService($versions, $audit),
                BackupJobRepositoryInterface::class => static fn (Connection $connection): BackupJobRepositoryInterface =>
                    new DatabaseBackupJobRepository($connection),
                BackupObjectStorageInterface::class => static fn (): BackupObjectStorageInterface =>
                    self::backupObjectStorage($settings),
                BackupSourceRegistry::class => static fn (): BackupSourceRegistry =>
                    self::backupSourceRegistry($settings),
                MysqlBackupRunnerInterface::class => static fn (): MysqlBackupRunnerInterface =>
                    self::mysqlBackupRunner($settings),
                BackupInventory::class => static fn (Connection $connection): BackupInventory => new BackupInventory($connection),
                BackupService::class => static fn (
                    BackupJobRepositoryInterface $jobs,
                    AuditLogService $audit,
                ): BackupService => new BackupService(
                    $jobs,
                    $audit,
                    (string) ($settings['app']['env'] ?? 'production'),
                    is_array($settings['backup']['restore_allowed_environments'] ?? null)
                        ? array_values(array_map('strval', $settings['backup']['restore_allowed_environments']))
                        : ['staging'],
                ),
                BackupExecutor::class => static fn (
                    BackupJobRepositoryInterface $jobs,
                    MysqlBackupRunnerInterface $mysql,
                    BackupObjectStorageInterface $storage,
                    BackupSourceRegistry $sourceRegistry,
                    BackupInventory $inventory,
                    AuditLogService $audit,
                ): BackupExecutor => new BackupExecutor(
                    $jobs,
                    $mysql,
                    $storage,
                    $sourceRegistry,
                    $inventory,
                    $audit,
                    (string) ($settings['backup']['base_object_key'] ?? 'backups'),
                    (string) ($settings['backup']['temp_dir'] ?? ''),
                    is_array($settings['backup']['restore_allowed_environments'] ?? null)
                        ? array_values(array_map('strval', $settings['backup']['restore_allowed_environments']))
                        : ['staging'],
                ),
                OperationsSummaryService::class => static fn (
                    IpGeoRepositoryInterface $ipGeoRepository,
                    BackupJobRepositoryInterface $backupJobs,
                    Connection $connection,
                ): OperationsSummaryService => new OperationsSummaryService(
                    backupStatus: $settings['operations']['backup_status'] ?? [
                        'status' => 'unknown',
                        'last_backup_at' => null,
                        'last_successful_backup_id' => null,
                    ],
                    restoreDrillEvidence: $settings['operations']['restore_drill_evidence'] ?? [
                        'last_drill_at' => null,
                        'evidence_url' => null,
                        'verified_by' => null,
                    ],
                    redisHardeningInventory: array_merge([
                        'password_configured' => (string) ($settings['redis']['password'] ?? '') !== '',
                        'dangerous_commands_disabled' => [],
                        'key_prefix' => (string) ($settings['redis']['prefix'] ?? 'vertoad:'),
                        'prefix_collision_risk' => 'unknown',
                        'auth_failure_alerting_configured' => false,
                    ], $settings['operations']['redis_hardening_inventory'] ?? []),
                    ipGeoRepository: $ipGeoRepository,
                    backupJobs: self::backupSummaryRepository($settings, $backupJobs, $connection),
                ),
                WebhookSigner::class => static fn (): WebhookSigner => new WebhookSigner(
                    (string) ($settings['webhooks']['signing_secret'] ?? 'whsec_local_dev_secret'),
                ),
                WebhookEventDeliveryRepositoryInterface::class => static fn (Connection $connection): WebhookEventDeliveryRepositoryInterface =>
                    new DatabaseWebhookDeliveryRepository($connection),
                WebhookDeliveryRepositoryInterface::class => static fn (
                    WebhookEventDeliveryRepositoryInterface $repository,
                ): WebhookDeliveryRepositoryInterface => $repository,
                WebhookEndpointRepositoryInterface::class => static fn (Connection $connection): WebhookEndpointRepositoryInterface =>
                    new DatabaseWebhookEndpointRepository($connection),
                WebhookOutboxRepositoryInterface::class => static fn (Connection $connection): WebhookOutboxRepositoryInterface =>
                    new DatabaseWebhookOutboxRepository($connection),
                WebhookEndpointSecretCipherInterface::class => static fn (): WebhookEndpointSecretCipherInterface =>
                    new WebhookEndpointSecretCipher((string) ($settings['app']['key'] ?? '')),
                WebhookOutboxDispatchService::class => static fn (
                    Connection $connection,
                    WebhookOutboxRepositoryInterface $outbox,
                    WebhookEndpointRepositoryInterface $endpoints,
                    WebhookEventDeliveryRepositoryInterface $deliveries,
                ): WebhookOutboxDispatchService => new WebhookOutboxDispatchService(
                    $connection,
                    $outbox,
                    $endpoints,
                    $deliveries,
                ),
                WebhookDeliveryJob::class => static function (
                    WebhookDeliveryRepositoryInterface $deliveries,
                    WebhookEndpointRepositoryInterface $endpoints,
                    WebhookEndpointSecretCipherInterface $secrets,
                    WebhookOutboxDispatchService $outboxDispatcher,
                    SystemConfigService $configs,
                ): WebhookDeliveryJob {
                    $policy = $configs->webhookDeliveryPolicy();

                    return new WebhookDeliveryJob(
                        $deliveries,
                        $endpoints,
                        $secrets,
                        WebhookDeliveryJob::httpTransport($policy->httpTimeoutSeconds),
                        $policy->batchSize,
                        $policy->maxRetryCount,
                        $policy->retryBaseBackoffSeconds,
                        $outboxDispatcher,
                    );
                },
                RealTimeGeoLookupInterface::class => static fn (
                    IpGeoRepositoryInterface $repository,
                    IpGeoProviderSelector $selector,
                    MappedHttpIpGeoProviderClient $client,
                    IpGeoProviderPolicy $policy,
                ): RealTimeGeoLookupInterface => new RepositoryRealTimeGeoLookup($repository, $selector, $client, $policy),
                SupportTicketRepositoryInterface::class => static fn (Connection $connection): SupportTicketRepositoryInterface =>
                    new DatabaseSupportTicketRepository($connection),
                SupportTicketService::class => static fn (
                    SupportTicketRepositoryInterface $tickets,
                    AuditLogService $audit,
                ): SupportTicketService => new SupportTicketService($tickets, $audit),
                FeatureFlagRepositoryInterface::class => static fn (Connection $connection): FeatureFlagRepositoryInterface =>
                    new DatabaseFeatureFlagRepository($connection),
                FeatureFlagService::class => static fn (
                    FeatureFlagRepositoryInterface $flags,
                    AuditLogService $audit,
                ): FeatureFlagService => new FeatureFlagService($flags, $audit),
                AdServingService::class => static fn (
                    ServingInventoryRepositoryInterface $inventory,
                    AdCandidateRepositoryInterface $candidates,
                    AdDecisionRepositoryInterface $decisions,
                    AdEventRepositoryInterface $events,
                    CampaignSpendEligibilityInterface $spendEligibility,
                    AdSelectionPolicyInterface $selectionPolicy,
                    SystemConfigService $configs,
                    OperationRiskDecisionLogRepositoryInterface $riskDecisions,
                    CpmBillingService $cpmBilling,
                ): AdServingService => new AdServingService(
                    $inventory,
                    $candidates,
                    $decisions,
                    $events,
                    $spendEligibility,
                    $selectionPolicy,
                    $configs->servingEventPolicy(),
                    $riskDecisions,
                    fabricRendererUrl: self::integrationPublicBaseUrl('SDK_PUBLIC_BASE_URL', $settings)
                        . '/fabric-renderer.js',
                    serveEvents: $events instanceof ServingRequestEventBufferInterface ? $events : null,
                    cpmChargeEstimator: $cpmBilling,
                ),
                AuditLogRepositoryInterface::class => static fn (Connection $connection): AuditLogRepositoryInterface =>
                    new AuditLogRepository($connection),
                AuditLogService::class => static fn (AuditLogRepositoryInterface $repository): AuditLogService =>
                    new AuditLogService($repository),
                PointsLedgerRepositoryInterface::class => static fn (Connection $connection): PointsLedgerRepositoryInterface =>
                    new PointsLedgerRepository($connection),
                PointsLedgerService::class => static fn (PointsLedgerRepositoryInterface $repository): PointsLedgerService =>
                    new PointsLedgerService($repository),
                RevenueShareRepository::class => static fn (Connection $connection): RevenueShareRepository =>
                    new RevenueShareRepository($connection),
                CpmBillingRepositoryInterface::class => static fn (Connection $connection): CpmBillingRepositoryInterface =>
                    new DatabaseCpmBillingRepository($connection),
                RevenueShareService::class => static fn (
                    RevenueShareRepository $repository,
                    PointsLedgerService $ledger,
                ): RevenueShareService => new RevenueShareService($repository, $ledger),
                CpmBillingService::class => static fn (
                    CpmBillingRepositoryInterface $repository,
                    CampaignBudgetService $budgets,
                    RevenueShareService $revenueShare,
                ): CpmBillingService => new CpmBillingService($repository, $budgets, $revenueShare),
                AdEventBillingService::class => static fn (
                    CampaignBudgetService $budgets,
                    RevenueShareService $revenueShare,
                    Connection $connection,
                    CpmBillingService $cpmBilling,
                ): AdEventBillingService => new AdEventBillingService($budgets, $revenueShare, $connection, $cpmBilling),
                WithdrawalRepository::class => static fn (Connection $connection): WithdrawalRepository =>
                    new WithdrawalRepository($connection),
                WithdrawalService::class => static fn (
                    WithdrawalRepository $repository,
                    PointsLedgerService $ledger,
                    PointsLedgerRepositoryInterface $ledgerRepository,
                ): WithdrawalService => new WithdrawalService($repository, $ledger, $ledgerRepository),
                WithdrawalProofService::class => static function (WithdrawalRepository $repository) use ($settings): WithdrawalProofService {
                    $config = self::withdrawalProofStorageConfig($settings);
                    if (self::localFallbackAllowed($settings)) {
                        return new WithdrawalProofService(
                            $repository,
                            new DeterministicPresignedUploadSigner($config),
                            static fn (): string => bin2hex(random_bytes(12)),
                            new UnavailableObjectStorageInspector(),
                        );
                    }

                    return new WithdrawalProofService(
                        $repository,
                        new AwsS3PresignedUploadSigner($config),
                        static fn (): string => bin2hex(random_bytes(12)),
                        new S3ObjectStorageInspector($config),
                    );
                },
                RechargeKeyPlaintextCipherInterface::class => static fn (): RechargeKeyPlaintextCipherInterface =>
                    new DefuseRechargeKeyPlaintextCipher((string) ($settings['app']['key'] ?? '')),
                RechargeKeyRepositoryInterface::class => static fn (Connection $connection): RechargeKeyRepositoryInterface =>
                    new RechargeKeyRepository($connection),
                RechargeKeyService::class => static fn (
                    RechargeKeyRepositoryInterface $repository,
                    PointsLedgerService $ledger,
                    RechargeKeyPlaintextCipherInterface $cipher,
                    AuditLogService $audit,
                ): RechargeKeyService => new RechargeKeyService($repository, $ledger, $cipher, audit: $audit),
                SystemConfigRepositoryInterface::class => static fn (Connection $connection): SystemConfigRepositoryInterface =>
                    new SystemConfigRepository($connection),
                SystemConfigService::class => static fn (SystemConfigRepositoryInterface $repository): SystemConfigService =>
                    new SystemConfigService($repository, self::localFallbackAllowed($settings)),
                RuntimeConfigHealthCheck::class => static fn (
                    SystemConfigService $configs,
                    RevenueShareRepository $revenueShares,
                ): RuntimeConfigHealthCheck =>
                    RuntimeConfigHealthCheck::fromSystemConfig(
                        $configs,
                        self::localFallbackAllowed($settings) ? null : $revenueShares,
                        self::localFallbackAllowed($settings)
                            ? null
                            : static function () use ($settings): string {
                                $config = $settings['ai_review'] ?? [];

                                return is_array($config) ? (string) ($config['api_key'] ?? '') : '';
                            },
                        self::localFallbackAllowed($settings)
                            ? null
                            : static function () use ($settings): string {
                                $config = $settings['turnstile'] ?? [];

                                return is_array($config) ? (string) ($config['secret_key'] ?? '') : '';
                            },
                    ),
                CronLockStoreInterface::class => static fn (): CronLockStoreInterface =>
                    self::cronLockStore($settings),
                EventConsumptionJob::class => static fn (
                    ServingEventBufferInterface $events,
                    DatabaseAdEventRepository $persistence,
                    AdEventBillingService $billing,
                ): EventConsumptionJob => new EventConsumptionJob(
                    $events,
                    $persistence,
                    $billing,
                    (int) ($settings['cron']['event_consume_batch_size'] ?? 500),
                ),
                ExpiredTokenCleanupJob::class => static fn (
                    OAuthTokenRepositoryInterface $tokens,
                ): ExpiredTokenCleanupJob => new ExpiredTokenCleanupJob(
                    $tokens,
                    (int) ($settings['cron']['expired_token_retention_seconds'] ?? 86400),
                ),
                AiReviewQueueJob::class => static fn (
                    ReviewRepositoryInterface $reviews,
                    CreativeReviewProviderInterface $provider,
                ): AiReviewQueueJob => new AiReviewQueueJob(
                    $reviews,
                    $provider,
                    (int) ($settings['cron']['ai_review_batch_size'] ?? 50),
                ),
                ConfigCacheRefreshJob::class => static fn (
                    SystemConfigRepositoryInterface $configs,
                ): ConfigCacheRefreshJob => new ConfigCacheRefreshJob(
                    $configs,
                    self::configCacheRedisClient($settings),
                    (string) ($settings['redis']['prefix'] ?? 'vertoad:local:'),
                    (int) ($settings['cron']['config_cache_ttl_seconds'] ?? 300),
                ),
                AggregateStatisticsJob::class => static fn (
                    DatabaseReportAggregateRepository $aggregates,
                ): AggregateStatisticsJob => self::aggregateStatisticsJob(
                    $aggregates,
                    (int) ($settings['cron']['aggregate_statistics_lookback_hours'] ?? 24),
                ),
                FraudFeatureComputeJob::class => static fn (
                    DatabaseFraudRiskFeatureRepository $features,
                ): FraudFeatureComputeJob => self::fraudFeatureComputeJob(
                    $features,
                    (int) ($settings['cron']['fraud_feature_lookback_hours'] ?? 24),
                ),
                ArchiveParquetJob::class => static fn (ArchiveJob $archive): ArchiveParquetJob =>
                    new ArchiveParquetJob($archive),
                DuckDbColdQueryJob::class => static fn (ColdQueryService $queries): DuckDbColdQueryJob =>
                    new DuckDbColdQueryJob($queries),
                BackupCheckJob::class => static fn (OperationsSummaryService $operations): BackupCheckJob =>
                    new BackupCheckJob($operations),
                BackupCreateJob::class => static fn (BackupExecutor $executor): BackupCreateJob =>
                    new BackupCreateJob($executor),
                BackupRestoreJob::class => static fn (BackupExecutor $executor): BackupRestoreJob =>
                    new BackupRestoreJob($executor),
                EventTablePartitionMaintainer::class => static fn (Connection $connection): EventTablePartitionMaintainer =>
                    new EventTablePartitionMaintainer(
                        $connection,
                        (int) ($settings['cron']['partition_maintenance_lookahead_months'] ?? 3),
                    ),
                PartitionMaintenanceJob::class => static fn (
                    EventTablePartitionMaintainer $maintainer,
                ): PartitionMaintenanceJob => new PartitionMaintenanceJob($maintainer),
                IpGeoLookupJob::class => static fn (
                    IpGeoRepositoryInterface $repository,
                    IpGeoProviderSelector $selector,
                    MappedHttpIpGeoProviderClient $client,
                    IpGeoProviderPolicy $policy,
                ): IpGeoLookupJob => new IpGeoLookupJob($repository, $selector, $client, $policy),
                AssetSnapshotGenerationJob::class => static fn (
                    AssetSnapshotJobRepositoryInterface $jobs,
                    AssetObjectStorageInterface $storage,
                    AssetSnapshotGenerator $generator,
                ): AssetSnapshotGenerationJob => new AssetSnapshotGenerationJob(
                    $jobs,
                    $storage,
                    $generator,
                    (int) ($settings['cron']['asset_snapshot_batch_size'] ?? 25),
                    (int) ($settings['cron']['asset_snapshot_lease_seconds'] ?? 300),
                    (int) ($settings['cron']['asset_snapshot_max_attempts'] ?? 3),
                    (int) ($settings['cron']['asset_snapshot_retry_backoff_seconds'] ?? 300),
                ),
                CronJobRegistry::class => static function (
                    EventConsumptionJob $eventConsumption,
                    WebhookDeliveryJob $webhookDelivery,
                    ExpiredTokenCleanupJob $expiredTokenCleanup,
                    AiReviewQueueJob $aiReviewQueue,
                    ConfigCacheRefreshJob $configCacheRefresh,
                    AggregateStatisticsJob $aggregateStatistics,
                    FraudFeatureComputeJob $fraudFeatureCompute,
                    ArchiveParquetJob $archiveParquet,
                    DuckDbColdQueryJob $duckDbColdQuery,
                    BackupCheckJob $backupCheck,
                    BackupCreateJob $backupCreate,
                    BackupRestoreJob $backupRestore,
                    PartitionMaintenanceJob $partitionMaintenance,
                    IpGeoLookupJob $ipGeoLookup,
                    AssetSnapshotGenerationJob $assetSnapshotGeneration,
                ) use ($settings): CronJobRegistry {
                    $jobs = [
                        $eventConsumption,
                        $webhookDelivery,
                        $expiredTokenCleanup,
                        $aiReviewQueue,
                        $configCacheRefresh,
                        $aggregateStatistics,
                        $fraudFeatureCompute,
                        $archiveParquet,
                        $duckDbColdQuery,
                        $backupCheck,
                        $backupCreate,
                        $backupRestore,
                        $partitionMaintenance,
                        $ipGeoLookup,
                        $assetSnapshotGeneration,
                    ];
                    $registeredNames = array_fill_keys(array_map(
                        static fn (CronJobInterface $job): string => $job->name(),
                        $jobs,
                    ), true);
                    foreach (($settings['cron']['jobs'] ?? []) as $jobName) {
                        if (isset($registeredNames[(string) $jobName])) {
                            continue;
                        }

                        $jobs[] = new NoOpCronJob((string) $jobName);
                    }

                    return new CronJobRegistry($jobs);
                },
                CronRunner::class => static fn (
                    CronJobRegistry $registry,
                    CronLockStoreInterface $locks,
                ): CronRunner => new CronRunner(
                    $registry,
                    $locks,
                    (int) ($settings['cron']['lock_ttl_seconds'] ?? 300),
                ),
                CronStatusAction::class => static fn (): CronStatusAction => new CronStatusAction($settings),
                CronRunAction::class => static fn (CronRunner $runner): CronRunAction => new CronRunAction($runner),
                CronAuthMiddleware::class => static fn (ClientIpResolver $ipResolver): CronAuthMiddleware => new CronAuthMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $settings,
                    $ipResolver,
                ),
                TurnstilePolicy::class => static fn (SystemConfigService $configs): TurnstilePolicy =>
                    $configs->turnstilePolicy(),
                TurnstileVerifier::class => static fn (TurnstilePolicy $policy): TurnstileVerifier => new TurnstileVerifier(
                    (string) ($settings['turnstile']['secret_key'] ?? ''),
                    TurnstileVerifier::CLOUDFLARE_SITEVERIFY_URL,
                    timeoutSeconds: $policy->timeoutSeconds,
                    allowUnconfiguredSuccess: self::localFallbackAllowed($settings),
                ),
                ClientIpResolver::class => static fn (): ClientIpResolver =>
                    ClientIpResolver::fromSettings($settings['cloudflare'] ?? []),
                TurnstileMiddleware::class => static fn (
                    TurnstileVerifier $verifier,
                    AuditLogService $audit,
                    ClientIpResolver $ipResolver,
                    TurnstilePolicy $policy,
                ): TurnstileMiddleware => new TurnstileMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $verifier,
                    $audit,
                    $ipResolver,
                    $policy,
                    allowRuntimeBypass: self::localFallbackAllowed($settings),
                ),
                RateLimitStoreInterface::class => static fn (): RateLimitStoreInterface =>
                    self::rateLimitStore($settings),
                RateLimiter::class => static fn (RateLimitStoreInterface $store): RateLimiter => new RateLimiter($store),
                RateLimitPolicy::class => static fn (SystemConfigService $configs): RateLimitPolicy =>
                    $configs->rateLimitPolicy(),
                HealthAction::class => static fn (RuntimeConfigHealthCheck $runtimeConfigHealthCheck): HealthAction =>
                    new HealthAction($runtimeConfigHealthCheck),
                RateLimitMiddleware::class => static fn (
                    RateLimiter $limiter,
                    RateLimitPolicy $policy,
                    AuditLogService $audit,
                    ClientIpResolver $ipResolver,
                ): RateLimitMiddleware => new RateLimitMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $limiter,
                    $policy,
                    $audit,
                    null,
                    $ipResolver,
                ),
            ])
            ->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->add(new LazyContainerMiddleware($container, IpGeoMiddleware::class, [
            'GET:/api/v1/ads/serve',
            'POST:/api/v1/ads/serve',
            'POST:/api/v1/ads/track',
            'GET:/api/v1/ads/click',
        ]));

        $routes = require $rootPath . '/config/routes.php';
        $routes($app);
        $app->any('/install', new InstalledInstallAction());

        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware((bool) $settings['app']['debug'], true, true);
        $errorMiddleware->setDefaultErrorHandler($container->get(OperationErrorHandler::class));
        $app->add(new CorsMiddleware(
            $app->getResponseFactory(),
            is_array($settings['cors']['allowed_origins'] ?? null)
                ? array_values(array_map('strval', $settings['cors']['allowed_origins']))
                : [],
        ));
        $app->add(new WebhookRequestIdMiddleware($container->get(Connection::class)));
        $app->add(new RequestIdMiddleware());

        return $app;
    }

    /** @param array<string, mixed> $settings */
    private static function createInstallerApp(
        string $rootPath,
        string $environmentPath,
        array $settings,
        InstallState $state,
    ): App
    {
        $container = (new ContainerBuilder())->build();
        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $filesystem = new InstallFilesystem($rootPath, environmentPath: $environmentPath);
        $configuredEnvironment = getenv('APP_ENV');
        $installer = new InstallerService(
            $configuredEnvironment === false ? '' : $configuredEnvironment,
            $state,
            $filesystem,
            new PhinxMigrationRunner($rootPath),
            new BootstrapSeeder(),
            new InstallSecretGenerator(),
        );
        $security = new InstallSecurity((string) ($settings['install']['token'] ?? ''));

        $app->addBodyParsingMiddleware();
        $app->map(['GET', 'POST'], '/install', new InstallAction(
            $security,
            $installer,
            ClientIpResolver::fromSettings($settings['cloudflare'] ?? []),
        ));
        $app->any('/{path:.*}', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $requestId = RequestIdContext::ensure($request);
            $response->getBody()->write(json_encode([
                'data' => null,
                'error' => [
                    'code' => 'installation_required',
                    'message' => 'VertoAD must be installed before the API can serve requests.',
                ],
                'meta' => ['api_version' => 'v1'],
                'request_id' => $requestId,
            ], JSON_THROW_ON_ERROR));

            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store, max-age=0')
                ->withHeader('Retry-After', '60');
        });
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);
        $app->add(new RequestIdMiddleware());

        return $app;
    }

    /** @param array<string, mixed> $settings */
    private static function objectStorageUploadSigner(array $settings): ObjectStorageUploadSignerInterface
    {
        $config = $settings['storage']['s3'] ?? [];
        $config = is_array($config) ? $config : [];
        if (self::localFallbackAllowed($settings)) {
            return new DeterministicPresignedUploadSigner($config);
        }

        return new AwsS3PresignedUploadSigner($config);
    }

    /** @param array<string, mixed> $settings */
    private static function objectStorageInspector(array $settings): ObjectStorageInspectorInterface
    {
        $config = $settings['storage']['s3'] ?? [];
        $config = is_array($config) ? $config : [];

        if (self::localFallbackAllowed($settings)) {
            if (trim((string) ($config['public_base_url'] ?? '')) !== '') {
                return new PublicUrlObjectStorageInspector($config);
            }

            return new UnavailableObjectStorageInspector();
        }

        // Confirmation is authoritative and must bypass public CDN caches.
        return new S3AssetObjectStorage($config);
    }

    /** @param array<string, mixed> $settings */
    private static function assetPublicUrlResolver(array $settings): AssetPublicUrlResolver
    {
        $config = $settings['storage']['s3'] ?? [];
        $config = is_array($config) ? $config : [];
        $publicBaseUrl = trim((string) ($config['public_base_url'] ?? ''));
        $allowHttp = self::localFallbackAllowed($settings);
        if ($publicBaseUrl === '') {
            if (!$allowHttp) {
                throw new \RuntimeException('R2_PUBLIC_BASE_URL is required for public asset delivery.');
            }

            $endpoint = rtrim(trim((string) ($config['endpoint'] ?? '')), '/');
            if ($endpoint === '') {
                $endpoint = 'http://localhost:9000';
            }
            $bucket = trim((string) ($config['bucket'] ?? 'vertoad'));
            $publicBaseUrl = $endpoint . '/' . rawurlencode($bucket === '' ? 'vertoad' : $bucket);
        }

        return new AssetPublicUrlResolver($publicBaseUrl, $allowHttp);
    }

    /** @param array<string, mixed> $settings */
    private static function assetObjectStorage(array $settings): AssetObjectStorageInterface
    {
        $config = $settings['storage']['s3'] ?? [];
        $config = is_array($config) ? $config : [];
        $connectionValues = array_map(
            static fn (string $key): string => trim((string) ($config[$key] ?? '')),
            ['endpoint', 'access_key_id', 'secret_access_key'],
        );
        if (self::localFallbackAllowed($settings) && $connectionValues === ['', '', '']) {
            return new UnavailableAssetObjectStorage();
        }

        return new S3AssetObjectStorage($config);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function withdrawalProofStorageConfig(array $settings): array
    {
        $proofs = $settings['withdrawal_proofs'] ?? [];
        $proofs = is_array($proofs) ? $proofs : [];
        $config = $proofs['s3'] ?? [];
        $config = is_array($config) ? $config : [];
        $required = ['endpoint', 'bucket', 'access_key_id', 'secret_access_key'];
        $configuredCount = count(array_filter(
            $required,
            static fn (string $key): bool => trim((string) ($config[$key] ?? '')) !== '',
        ));

        if ($configuredCount === 0 && self::localFallbackAllowed($settings)) {
            $primary = $settings['storage']['s3'] ?? [];
            return is_array($primary) ? $primary : [];
        }
        if ($configuredCount !== count($required)) {
            throw new \RuntimeException('Dedicated WITHDRAWAL_PROOF_S3_* storage credentials are required.');
        }

        $maxInspectBytes = (int) ($config['max_inspect_bytes'] ?? WithdrawalProofPolicy::DEFAULT_MAX_BYTES);
        if ($maxInspectBytes !== WithdrawalProofPolicy::DEFAULT_MAX_BYTES) {
            throw new \RuntimeException('WITHDRAWAL_PROOF_S3_MAX_INSPECT_BYTES must equal the payment proof policy limit.');
        }

        $serverSideEncryption = trim((string) ($config['server_side_encryption'] ?? ''));
        if (!in_array($serverSideEncryption, ['AES256', 'aws:kms'], true)) {
            throw new \RuntimeException('WITHDRAWAL_PROOF_S3_SERVER_SIDE_ENCRYPTION must be AES256 or aws:kms.');
        }

        if (!self::localFallbackAllowed($settings)) {
            $endpoint = parse_url((string) $config['endpoint']);
            if (
                !is_array($endpoint)
                || strtolower((string) ($endpoint['scheme'] ?? '')) !== 'https'
                || trim((string) ($endpoint['host'] ?? '')) === ''
            ) {
                throw new \RuntimeException('WITHDRAWAL_PROOF_S3_ENDPOINT must use HTTPS outside local/testing.');
            }
            if (
                isset($endpoint['user'])
                || isset($endpoint['pass'])
                || isset($endpoint['query'])
                || isset($endpoint['fragment'])
            ) {
                throw new \RuntimeException('WITHDRAWAL_PROOF_S3_ENDPOINT must not contain credentials, a query, or a fragment.');
            }

            $primary = $settings['storage']['s3'] ?? [];
            $primary = is_array($primary) ? $primary : [];
            if (self::sameS3Location($config, $primary)) {
                throw new \RuntimeException('Withdrawal proof storage must not reuse the public asset bucket.');
            }

            $backup = $settings['backup']['s3'] ?? [];
            $backup = is_array($backup) ? $backup : [];
            if (self::sameS3Location($config, $backup)) {
                throw new \RuntimeException('Withdrawal proof storage must not reuse the backup target bucket.');
            }
        }

        return $config;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private static function sameS3Location(array $left, array $right): bool
    {
        $leftEndpoint = self::normalizedS3Endpoint((string) ($left['endpoint'] ?? ''));
        $rightEndpoint = self::normalizedS3Endpoint((string) ($right['endpoint'] ?? ''));
        $leftBucket = strtolower(trim((string) ($left['bucket'] ?? '')));
        $rightBucket = strtolower(trim((string) ($right['bucket'] ?? '')));

        return $leftEndpoint !== ''
            && $leftBucket !== ''
            && $leftEndpoint === $rightEndpoint
            && $leftBucket === $rightBucket;
    }

    /** @param array<string, mixed> $settings */
    private static function backupSourceRegistry(array $settings): BackupSourceRegistry
    {
        $primaryConfig = $settings['storage']['s3'] ?? [];
        $primaryConfig = is_array($primaryConfig) ? $primaryConfig : [];
        $primaryStorage = self::backupSourceStorage($primaryConfig, $settings, 'Primary asset/archive');
        $proofStorage = self::backupSourceStorage(
            self::withdrawalProofStorageConfig($settings),
            $settings,
            'Withdrawal proof',
        );

        return new BackupSourceRegistry([
            BackupSourceRegistry::ASSETS => $primaryStorage,
            BackupSourceRegistry::WITHDRAWAL_PROOFS => $proofStorage,
            BackupSourceRegistry::ARCHIVE => $primaryStorage,
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $settings
     */
    private static function backupSourceStorage(
        array $config,
        array $settings,
        string $label,
    ): BackupObjectStorageInterface {
        $configured = trim((string) ($config['endpoint'] ?? '')) !== ''
            && trim((string) ($config['bucket'] ?? '')) !== ''
            && trim((string) ($config['access_key_id'] ?? '')) !== ''
            && trim((string) ($config['secret_access_key'] ?? '')) !== '';
        if ($configured) {
            if (trim((string) ($config['server_side_encryption'] ?? '')) === '') {
                $config['server_side_encryption'] = 'AES256';
            }

            return new S3BackupObjectStorage($config);
        }
        if (self::localFallbackAllowed($settings)) {
            return new UnavailableBackupObjectStorage();
        }

        throw new \RuntimeException($label . ' S3 storage is required as a backup source outside local/testing.');
    }

    /** @param array<string, mixed> $settings */
    private static function backupObjectStorage(array $settings): BackupObjectStorageInterface
    {
        $backup = $settings['backup'] ?? [];
        $backup = is_array($backup) ? $backup : [];
        $config = $backup['s3'] ?? [];
        $config = is_array($config) ? $config : [];
        $config['server_side_encryption'] = (string) ($backup['server_side_encryption'] ?? 'AES256');
        $configured = trim((string) ($config['endpoint'] ?? '')) !== ''
            && trim((string) ($config['bucket'] ?? '')) !== ''
            && trim((string) ($config['access_key_id'] ?? '')) !== ''
            && trim((string) ($config['secret_access_key'] ?? '')) !== '';
        if ($configured) {
            if (!self::localFallbackAllowed($settings)) {
                $endpoint = parse_url((string) $config['endpoint']);
                if (
                    !is_array($endpoint)
                    || strtolower((string) ($endpoint['scheme'] ?? '')) !== 'https'
                    || trim((string) ($endpoint['host'] ?? '')) === ''
                ) {
                    throw new \RuntimeException('BACKUP_S3_ENDPOINT must use HTTPS outside local/testing.');
                }
                if (
                    isset($endpoint['user'])
                    || isset($endpoint['pass'])
                    || isset($endpoint['query'])
                    || isset($endpoint['fragment'])
                ) {
                    throw new \RuntimeException('BACKUP_S3_ENDPOINT must not contain credentials, a query, or a fragment.');
                }
                $primary = $settings['storage']['s3'] ?? [];
                $primary = is_array($primary) ? $primary : [];
                if (self::sameS3Location($config, $primary)) {
                    throw new \RuntimeException('Backup storage must use a bucket isolated from primary object storage.');
                }
            }

            return new S3BackupObjectStorage($config);
        }
        if (self::localFallbackAllowed($settings)) {
            return new UnavailableBackupObjectStorage();
        }

        throw new \RuntimeException('Dedicated BACKUP_S3_* object storage is required for production backups.');
    }

    private static function normalizedS3Endpoint(string $endpoint): string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return strtolower($endpoint);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $portSuffix = $port === null || $isDefaultPort ? '' : ':' . $port;
        $path = strtolower(rtrim((string) ($parts['path'] ?? ''), '/'));

        return $scheme . '://' . $host . $portSuffix . $path;
    }

    /** @param array<string, mixed> $settings */
    private static function mysqlBackupRunner(array $settings): MysqlBackupRunnerInterface
    {
        $database = $settings['database'] ?? [];
        $database = is_array($database) ? $database : [];
        $driver = strtolower(trim((string) ($database['driver'] ?? 'pdo_mysql')));
        $configured = $driver === 'pdo_mysql'
            && trim((string) ($database['host'] ?? '')) !== ''
            && trim((string) ($database['database'] ?? '')) !== ''
            && trim((string) ($database['username'] ?? '')) !== '';
        if (!$configured) {
            if (self::localFallbackAllowed($settings)) {
                return new UnavailableMysqlBackupRunner();
            }

            throw new \RuntimeException('A configured MySQL database is required for production backups.');
        }
        $backup = $settings['backup'] ?? [];
        $backup = is_array($backup) ? $backup : [];

        return new ProcessMysqlBackupRunner(
            database: $database,
            dumpBinary: (string) ($backup['mysql_dump_binary'] ?? 'mysqldump'),
            restoreBinary: (string) ($backup['mysql_restore_binary'] ?? 'mysql'),
            timeoutSeconds: (int) ($backup['command_timeout_seconds'] ?? 900),
        );
    }

    /** @param array<string, mixed> $settings */
    private static function backupSummaryRepository(
        array $settings,
        BackupJobRepositoryInterface $repository,
        Connection $connection,
    ): ?BackupJobRepositoryInterface {
        if (!self::localFallbackAllowed($settings)) {
            return $repository;
        }
        return $connection->createSchemaManager()->tablesExist(['operation_backup_jobs']) ? $repository : null;
    }

    /** @param array<string, mixed> $settings */
    private static function servingEventRepository(array $settings): AdEventRepositoryInterface
    {
        $env = (string) ($settings['app']['env'] ?? 'local');
        $localFallbackAllowed = self::localFallbackAllowed($settings);

        $redis = $settings['redis'] ?? [];
        if ((string) ($redis['password'] ?? '') === '') {
            if ($localFallbackAllowed) {
                return new InMemoryAdEventRepository();
            }

            throw new \RuntimeException('REDIS_PASSWORD is required for serving event buffering.');
        }

        return RedisAdEventRepository::fromSettings(is_array($redis) ? $redis : []);
    }

    /** @param array<string, mixed> $settings */
    private static function archiveWriter(array $settings): ArchiveWriterInterface
    {
        $archive = $settings['archive'] ?? [];
        $adapter = is_array($archive) ? (string) ($archive['writer'] ?? '') : '';
        if ($adapter === 'duckdb-s3') {
            return new DuckDbCliArchiveWriter(
                self::archiveObjectStorage($settings),
                self::archiveDuckDbBinary($settings),
                self::archiveTempDirectory($settings),
                timeoutSeconds: self::archiveCommandTimeoutSeconds($settings),
            );
        }

        if ($adapter === 'deterministic') {
            if (!self::localFallbackAllowed($settings)) {
                throw new \RuntimeException('ARCHIVE_WRITER=deterministic is only allowed in local/testing.');
            }

            return new DeterministicArchiveWriter();
        }

        if ($adapter === '' && self::localFallbackAllowed($settings)) {
            return new DeterministicArchiveWriter();
        }

        throw new \RuntimeException('ARCHIVE_WRITER must be configured outside local/testing.');
    }

    /** @param array<string, mixed> $settings */
    private static function coldQueryRunner(array $settings): ColdQueryRunnerInterface
    {
        $archive = $settings['archive'] ?? [];
        $adapter = is_array($archive) ? (string) ($archive['cold_query_runner'] ?? '') : '';
        if ($adapter === 'duckdb-s3') {
            return new DuckDbCliColdQueryRunner(
                self::archiveObjectStorage($settings),
                self::archiveDuckDbBinary($settings),
                self::archiveTempDirectory($settings),
                timeoutSeconds: self::archiveCommandTimeoutSeconds($settings),
                maxScannedObjects: self::positiveArchiveInt($settings, 'max_scanned_objects', 'ARCHIVE_MAX_SCANNED_OBJECTS'),
                maxResultBytes: self::positiveArchiveInt($settings, 'max_result_bytes', 'ARCHIVE_MAX_RESULT_BYTES'),
            );
        }

        if ($adapter === 'fixture') {
            if (!self::localFallbackAllowed($settings)) {
                throw new \RuntimeException('ARCHIVE_COLD_QUERY_RUNNER=fixture is only allowed in local/testing.');
            }

            return new FixtureColdQueryRunner();
        }

        if ($adapter === '' && self::localFallbackAllowed($settings)) {
            return new FixtureColdQueryRunner();
        }

        throw new \RuntimeException('ARCHIVE_COLD_QUERY_RUNNER must be configured outside local/testing.');
    }

    /** @param array<string, mixed> $settings */
    private static function archiveObjectStorage(array $settings): ArchiveObjectStorageInterface
    {
        $config = $settings['storage']['s3'] ?? [];
        $config = is_array($config) ? $config : [];

        return new S3ArchiveObjectStorage($config);
    }

    /** @param array<string, mixed> $settings */
    private static function archiveDuckDbBinary(array $settings): string
    {
        $archive = $settings['archive'] ?? [];
        $binary = is_array($archive) ? trim((string) ($archive['duckdb_binary'] ?? '')) : '';
        if ($binary === '') {
            throw new \RuntimeException('ARCHIVE_DUCKDB_BINARY is required for DuckDB archive adapters.');
        }

        return $binary;
    }

    /** @param array<string, mixed> $settings */
    private static function archiveTempDirectory(array $settings): string
    {
        $archive = $settings['archive'] ?? [];
        $temp = is_array($archive) ? trim((string) ($archive['temp_dir'] ?? '')) : '';
        if ($temp === '') {
            throw new \RuntimeException('ARCHIVE_TEMP_DIR is required for DuckDB archive adapters.');
        }

        return $temp;
    }

    /** @param array<string, mixed> $settings */
    private static function archiveCommandTimeoutSeconds(array $settings): int
    {
        return self::positiveArchiveInt($settings, 'command_timeout_seconds', 'ARCHIVE_COMMAND_TIMEOUT_SECONDS');
    }

    /** @param array<string, mixed> $settings */
    private static function positiveArchiveInt(array $settings, string $key, string $envName): int
    {
        $archive = $settings['archive'] ?? [];
        $value = is_array($archive) ? (int) ($archive[$key] ?? 0) : 0;
        if ($value <= 0) {
            throw new \InvalidArgumentException($envName . ' must be positive.');
        }

        return $value;
    }

    /** @param array<string, mixed> $settings */
    private static function creativeReviewProvider(array $settings, SystemConfigService $configs): CreativeReviewProviderInterface
    {
        $policy = $configs->aiReviewPolicy();
        $secretConfig = $settings['ai_review'] ?? [];
        $apiKey = is_array($secretConfig) ? trim((string) ($secretConfig['api_key'] ?? '')) : '';

        if ($policy->enabled && $apiKey !== '') {
            return new OpenAiCompatibleCreativeReviewProvider($policy->toProviderConfig($apiKey));
        }

        if (!self::localFallbackAllowed($settings)) {
            if (!$policy->enabled) {
                throw new \RuntimeException('review.ai_policy must enable AI review outside local/testing.');
            }

            throw new \RuntimeException('AI_REVIEW_API_KEY is required outside local/testing.');
        }

        return new DeterministicCreativeReviewProvider([
            'provider' => 'openai-compatible-deterministic',
            'model' => $policy->model,
        ]);
    }

    /** @param array<string, mixed> $settings */
    private static function cronLockStore(array $settings): CronLockStoreInterface
    {
        $redis = $settings['redis'] ?? [];
        if (!is_array($redis) || (string) ($redis['password'] ?? '') === '') {
            if (self::redisRequired($settings)) {
                throw new \RuntimeException('REDIS_PASSWORD is required for cron lock storage.');
            }

            return new InMemoryCronLockStore();
        }

        return RedisCronLockStore::fromSettings($redis);
    }

    /** @param array<string, mixed> $settings */
    private static function rateLimitStore(array $settings): RateLimitStoreInterface
    {
        $redis = $settings['redis'] ?? [];
        if (!is_array($redis) || (string) ($redis['password'] ?? '') === '') {
            if (self::redisRequired($settings)) {
                throw new \RuntimeException('REDIS_PASSWORD is required for rate limit storage.');
            }

            return new InMemoryRateLimitStore();
        }

        return RedisRateLimitStore::fromSettings($redis);
    }

    /** @param array<string, mixed> $settings */
    private static function configCacheRedisClient(array $settings): RedisClientInterface
    {
        $redis = $settings['redis'] ?? [];
        if (!is_array($redis) || (string) ($redis['password'] ?? '') === '') {
            if (self::redisRequired($settings)) {
                throw new \RuntimeException('REDIS_PASSWORD is required for config cache refresh.');
            }

            return new InMemoryRedisClient();
        }

        return RedisClientFactory::fromSettings($redis);
    }

    /** @param array<string, mixed> $settings */
    private static function servingFrequencyCapStore(array $settings): ServingFrequencyCapStoreInterface
    {
        $redis = $settings['redis'] ?? [];
        if (!is_array($redis) || (string) ($redis['password'] ?? '') === '') {
            if (self::redisRequired($settings)) {
                throw new \RuntimeException('REDIS_PASSWORD is required for serving frequency caps.');
            }

            return new InMemoryServingFrequencyCapStore();
        }

        return new RedisServingFrequencyCapStore(
            RedisClientFactory::fromSettings($redis),
            (string) ($redis['prefix'] ?? 'vertoad:'),
        );
    }

    /** @param array<string, mixed> $settings */
    private static function ipGeoRepository(array $settings, Connection $connection, ?IpGeoProviderPolicy $policy = null): IpGeoRepositoryInterface
    {
        $ipGeo = $settings['ip_geo'] ?? [];
        $adapterConfigured = is_array($ipGeo)
            && array_key_exists('repository', $ipGeo)
            && trim((string) ($ipGeo['repository'] ?? '')) !== '';
        $adapter = $adapterConfigured ? strtolower(trim((string) $ipGeo['repository'])) : 'database';
        $recordTtlSeconds = $policy?->cacheTtlSeconds ?? 604800;
        $visibilityTimeoutSeconds = is_array($ipGeo) ? (int) ($ipGeo['visibility_timeout_seconds'] ?? 300) : 300;

        if (!$adapterConfigured && self::localFallbackAllowed($settings)) {
            return new InMemoryIpGeoRepository();
        }

        if ($adapter === 'database') {
            return new DatabaseIpGeoRepository($connection, $recordTtlSeconds, $visibilityTimeoutSeconds);
        }

        if ($adapter === 'memory') {
            if (!self::localFallbackAllowed($settings)) {
                throw new \RuntimeException('IP_GEO_REPOSITORY=memory is only allowed in local/testing.');
            }

            return new InMemoryIpGeoRepository();
        }

        if ($adapter !== 'redis') {
            throw new \RuntimeException('IP_GEO_REPOSITORY must be one of database, redis, memory.');
        }

        $redis = $settings['redis'] ?? [];
        if (!is_array($redis) || (string) ($redis['password'] ?? '') === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for IP geo queue storage.');
        }

        $redis['ip_geo_record_ttl_seconds'] = $recordTtlSeconds;

        return RedisIpGeoRepository::fromSettings($redis);
    }

    private static function geoResolver(IpGeoRepositoryInterface $repository, IpGeoProviderPolicy $policy): GeoResolverInterface
    {
        if (!$policy->enabled) {
            return new NullGeoResolver();
        }

        return new AsyncIpGeoResolver($repository, $policy->queueSource);
    }

    private static function ipGeoRequestContextResolver(IpGeoRepositoryInterface $repository, IpGeoProviderPolicy $policy): IpGeoRequestContextResolverInterface
    {
        if (!$policy->enabled) {
            return new DisabledIpGeoRequestContextResolver();
        }

        return new AsyncIpGeoResolver($repository, $policy->queueSource);
    }

    /** @param array<string, mixed> $settings */
    private static function redisRequired(array $settings): bool
    {
        return !self::localFallbackAllowed($settings);
    }

    /** @param array<string, mixed> $settings */
    private static function integrationPublicBaseUrl(string $environmentVariable, array $settings): string
    {
        $localDefault = match ($environmentVariable) {
            'SDK_PUBLIC_BASE_URL' => 'http://localhost:5173',
            'ADS_PUBLIC_BASE_URL' => 'http://localhost:8080',
            default => throw new \InvalidArgumentException('Unsupported integration public base URL variable: ' . $environmentVariable),
        };

        $settingKey = match ($environmentVariable) {
            'SDK_PUBLIC_BASE_URL' => 'sdk_public_base_url',
            'ADS_PUBLIC_BASE_URL' => 'ads_public_base_url',
        };
        $integration = $settings['integration'] ?? [];
        $integration = is_array($integration) ? $integration : [];
        $explicit = trim((string) ($integration[$settingKey] ?? (getenv($environmentVariable) ?: '')));
        if ($explicit === '') {
            if (!self::localFallbackAllowed($settings)) {
                throw new \RuntimeException($environmentVariable . ' is required outside local/testing.');
            }

            return $localDefault;
        }

        $parts = parse_url($explicit);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? '')) : '';
        if (
            $parts === false
            || !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException(
                $environmentVariable . ' must be an HTTP(S) origin/base URL without credentials, query, or fragment.',
            );
        }

        if (!self::localFallbackAllowed($settings) && $scheme !== 'https') {
            throw new \RuntimeException($environmentVariable . ' must use HTTPS outside local/testing.');
        }

        if (
            $environmentVariable === 'SDK_PUBLIC_BASE_URL'
            && preg_match('/\.js\/?$/i', (string) ($parts['path'] ?? '')) === 1
        ) {
            throw new \RuntimeException('SDK_PUBLIC_BASE_URL must not include a script filename.');
        }

        return rtrim($explicit, '/');
    }

    /** @param array<string, mixed> $settings */
    private static function localFallbackAllowed(array $settings): bool
    {
        $env = (string) ($settings['app']['env'] ?? 'local');

        return in_array($env, ['local', 'test', 'testing'], true);
    }

    private static function aggregateStatisticsJob(DatabaseReportAggregateRepository $aggregates, int $lookbackHours): AggregateStatisticsJob
    {
        if ($lookbackHours <= 0) {
            throw new \InvalidArgumentException('CRON_AGGREGATE_STATISTICS_LOOKBACK_HOURS must be positive.');
        }

        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new AggregateStatisticsJob($aggregates, $to->modify('-' . $lookbackHours . ' hours'), $to);
    }

    private static function fraudFeatureComputeJob(DatabaseFraudRiskFeatureRepository $features, int $lookbackHours): FraudFeatureComputeJob
    {
        if ($lookbackHours <= 0) {
            throw new \InvalidArgumentException('CRON_FRAUD_FEATURE_LOOKBACK_HOURS must be positive.');
        }

        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new FraudFeatureComputeJob($features, $to->modify('-' . $lookbackHours . ' hours'), $to);
    }
}
