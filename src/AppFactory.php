<?php

declare(strict_types=1);

namespace VertoAD;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use VertoAD\Domain\Security\TurnstilePolicy;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Action\Cron\CronRunAction;
use VertoAD\Http\Action\HealthAction;
use VertoAD\Http\Error\OperationErrorHandler;
use VertoAD\Http\Auth\BearerTokenAuthenticator;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\CronAuthMiddleware;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\RateLimitMiddleware;
use VertoAD\Http\Middleware\TurnstileMiddleware;
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
use VertoAD\Infrastructure\Storage\UnavailableObjectStorageInspector;
use VertoAD\Repository\AdSlotRepository;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;
use VertoAD\Repository\Archive\DatabaseArchiveRepository;
use VertoAD\Repository\AuditLogRepository;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\Assets\AssetRepository;
use VertoAD\Repository\Assets\AssetRepositoryInterface;
use VertoAD\Repository\Attribution\AttributionEventRepositoryInterface;
use VertoAD\Repository\Attribution\DatabaseAttributionEventRepository;
use VertoAD\Repository\Billing\RevenueShareRepository;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\Campaign\CampaignRepository;
use VertoAD\Repository\Campaign\CampaignRepositoryInterface;
use VertoAD\Repository\CampaignBudgetRepository;
use VertoAD\Repository\CampaignBudgetRepositoryInterface;
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
use VertoAD\Repository\FirstPartySessionRepository;
use VertoAD\Repository\FirstPartySessionRepositoryInterface;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepository;
use VertoAD\Repository\OAuthConsentRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\Operations\ConfigVersionRepositoryInterface;
use VertoAD\Repository\Operations\DatabaseConfigVersionRepository;
use VertoAD\Repository\Operations\DatabaseOperationErrorLogRepository;
use VertoAD\Repository\Operations\OperationErrorLogRepositoryInterface;
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
use VertoAD\Repository\Reporting\InMemoryReportAggregateRepository;
use VertoAD\Repository\Reporting\ReportAggregateRepositoryInterface;
use VertoAD\Repository\SystemConfigRepository;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Repository\Support\DatabaseSupportTicketRepository;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Repository\UserIdentityRepository;
use VertoAD\Repository\UserIdentityRepositoryInterface;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\DatabaseWebhookEndpointRepository;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveService;
use VertoAD\Service\Archive\ArchiveWriterInterface;
use VertoAD\Service\Archive\ColdQueryRunnerInterface;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DeterministicArchiveWriter;
use VertoAD\Service\Archive\FixtureColdQueryRunner;
use VertoAD\Service\Cron\ArchiveParquetJob;
use VertoAD\Service\Cron\AiReviewQueueJob;
use VertoAD\Service\Cron\AggregateStatisticsJob;
use VertoAD\Service\Cron\BackupCheckJob;
use VertoAD\Service\Attribution\AttributionService;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\AuthService;
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
use VertoAD\Service\Cron\NoOpCronJob;
use VertoAD\Service\Cron\RedisCronLockStore;
use VertoAD\Service\DefuseRechargeKeyPlaintextCipher;
use VertoAD\Service\FeatureFlags\FeatureFlagService;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\OAuthTokenService;
use VertoAD\Service\Operations\ConfigVersionService;
use VertoAD\Service\Operations\OperationErrorCaptureService;
use VertoAD\Service\Operations\OperationsSummaryService;
use VertoAD\Service\Serving\AdServingService;
use VertoAD\Service\Serving\AdSelectionPolicyInterface;
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;
use VertoAD\Service\Serving\DatabaseServingRiskAssessor;
use VertoAD\Service\Serving\DefaultAdSelectionPolicy;
use VertoAD\Service\Serving\InMemoryServingFrequencyCapStore;
use VertoAD\Service\Serving\RedisServingFrequencyCapStore;
use VertoAD\Service\Serving\ServingFrequencyCapStoreInterface;
use VertoAD\Service\Serving\ServingRiskAssessorInterface;
use VertoAD\Service\PasswordHasher;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\PublisherSiteVerificationService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\Review\CreativeReviewProviderInterface;
use VertoAD\Service\Review\DeterministicCreativeReviewProvider;
use VertoAD\Service\Review\OpenAiCompatibleCreativeReviewProvider;
use VertoAD\Service\Reporting\ReportQueryService;
use VertoAD\Service\ReviewService;
use VertoAD\Service\RuntimeConfigHealthCheck;
use VertoAD\Service\SystemConfigService;
use VertoAD\Service\Support\SupportTicketService;
use VertoAD\Service\TenantAccessService;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipher;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;
use VertoAD\Service\Webhooks\WebhookSigner;

final class AppFactory
{
    public static function create(?string $basePath = null): App
    {
        $rootPath = $basePath ?? dirname(__DIR__);

        if (is_file($rootPath . '/.env')) {
            Dotenv::createUnsafeImmutable($rootPath)->safeLoad();
        }

        $settings = require $rootPath . '/config/settings.php';
        $container = (new ContainerBuilder())
            ->addDefinitions([
                'settings' => $settings,
                Connection::class => static fn (): Connection => (new ConnectionFactory())->create($settings['database']),
                UserIdentityRepositoryInterface::class => static fn (Connection $connection): UserIdentityRepositoryInterface =>
                    new UserIdentityRepository($connection),
                OrganizationMembershipRepositoryInterface::class => static fn (Connection $connection): OrganizationMembershipRepositoryInterface =>
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
                TenantAccessService::class => static fn (
                    OrganizationMembershipRepositoryInterface $memberships,
                    PermissionMatcher $permissions,
                ): TenantAccessService => new TenantAccessService($memberships, $permissions),
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
                AssetRepositoryInterface::class => static fn (Connection $connection): AssetRepositoryInterface =>
                    new AssetRepository($connection),
                ObjectStorageUploadSignerInterface::class => static fn (): ObjectStorageUploadSignerInterface =>
                    self::objectStorageUploadSigner($settings),
                ObjectStorageInspectorInterface::class => static fn (): ObjectStorageInspectorInterface =>
                    self::objectStorageInspector($settings),
                AssetUploadService::class => static fn (
                    AssetRepositoryInterface $repository,
                    ObjectStorageUploadSignerInterface $signer,
                    ObjectStorageInspectorInterface $inspector,
                    SystemConfigService $configs,
                ): AssetUploadService => new AssetUploadService($repository, $signer, $inspector, $configs->assetUploadPolicy()),
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
                ServingInventoryRepositoryInterface::class => static fn (Connection $connection): ServingInventoryRepositoryInterface =>
                    new DatabaseServingInventoryRepository($connection),
                AdCandidateRepositoryInterface::class => static fn (Connection $connection): AdCandidateRepositoryInterface =>
                    new DatabaseAdCandidateRepository($connection),
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
                ReportQueryService::class => static fn (
                    ReportAggregateRepositoryInterface $aggregates,
                ): ReportQueryService => new ReportQueryService($aggregates),
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
                OperationErrorCaptureService::class => static fn (
                    OperationErrorLogRepositoryInterface $errors,
                    AuditLogService $audit,
                ): OperationErrorCaptureService => new OperationErrorCaptureService($errors, $audit),
                OperationErrorHandler::class => static fn (
                    OperationErrorCaptureService $errors,
                ): OperationErrorHandler => new OperationErrorHandler(SlimAppFactory::determineResponseFactory(), $errors),
                ConfigVersionRepositoryInterface::class => static fn (Connection $connection): ConfigVersionRepositoryInterface =>
                    new DatabaseConfigVersionRepository($connection),
                ConfigVersionService::class => static fn (
                    ConfigVersionRepositoryInterface $versions,
                    AuditLogService $audit,
                ): ConfigVersionService => new ConfigVersionService($versions, $audit),
                OperationsSummaryService::class => static fn (): OperationsSummaryService => new OperationsSummaryService(
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
                ),
                WebhookSigner::class => static fn (): WebhookSigner => new WebhookSigner(
                    (string) ($settings['webhooks']['signing_secret'] ?? 'whsec_local_dev_secret'),
                ),
                WebhookDeliveryRepositoryInterface::class => static fn (Connection $connection): WebhookDeliveryRepositoryInterface =>
                    new DatabaseWebhookDeliveryRepository($connection),
                WebhookEndpointRepositoryInterface::class => static fn (Connection $connection): WebhookEndpointRepositoryInterface =>
                    new DatabaseWebhookEndpointRepository($connection),
                WebhookEndpointSecretCipherInterface::class => static fn (): WebhookEndpointSecretCipherInterface =>
                    new WebhookEndpointSecretCipher((string) ($settings['app']['key'] ?? '')),
                WebhookDeliveryJob::class => static function (
                    WebhookDeliveryRepositoryInterface $deliveries,
                    WebhookEndpointRepositoryInterface $endpoints,
                    WebhookEndpointSecretCipherInterface $secrets,
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
                    );
                },
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
                ): AdServingService => new AdServingService(
                    $inventory,
                    $candidates,
                    $decisions,
                    $events,
                    $spendEligibility,
                    $selectionPolicy,
                    $configs->servingEventPolicy(),
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
                RevenueShareService::class => static fn (
                    RevenueShareRepository $repository,
                    PointsLedgerService $ledger,
                ): RevenueShareService => new RevenueShareService($repository, $ledger),
                AdEventBillingService::class => static fn (
                    CampaignBudgetService $budgets,
                    RevenueShareService $revenueShare,
                    Connection $connection,
                ): AdEventBillingService => new AdEventBillingService($budgets, $revenueShare, $connection),
                WithdrawalRepository::class => static fn (Connection $connection): WithdrawalRepository =>
                    new WithdrawalRepository($connection),
                WithdrawalService::class => static fn (
                    WithdrawalRepository $repository,
                    PointsLedgerService $ledger,
                    PointsLedgerRepositoryInterface $ledgerRepository,
                ): WithdrawalService => new WithdrawalService($repository, $ledger, $ledgerRepository),
                WithdrawalProofService::class => static fn (
                    WithdrawalRepository $repository,
                    ObjectStorageUploadSignerInterface $signer,
                ): WithdrawalProofService => new WithdrawalProofService(
                    $repository,
                    $signer,
                    static fn (): string => bin2hex(random_bytes(12)),
                ),
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

        $routes = require $rootPath . '/config/routes.php';
        $routes($app);

        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware((bool) $settings['app']['debug'], true, true);
        $errorMiddleware->setDefaultErrorHandler($container->get(OperationErrorHandler::class));

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
        $publicBaseUrl = is_array($config) ? trim((string) ($config['public_base_url'] ?? '')) : '';
        if ($publicBaseUrl !== '') {
            return new PublicUrlObjectStorageInspector($config);
        }

        if (self::localFallbackAllowed($settings)) {
            return new UnavailableObjectStorageInspector();
        }

        throw new \RuntimeException('R2_PUBLIC_BASE_URL is required for uploaded asset inspection.');
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
    private static function redisRequired(array $settings): bool
    {
        return !self::localFallbackAllowed($settings);
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
