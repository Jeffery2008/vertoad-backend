<?php

declare(strict_types=1);

namespace VertoAD;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Action\Cron\CronRunAction;
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
use VertoAD\Infrastructure\Security\InMemoryRateLimitStore;
use VertoAD\Infrastructure\Security\RateLimiter;
use VertoAD\Infrastructure\Security\RateLimitPolicy;
use VertoAD\Infrastructure\Security\RateLimitStoreInterface;
use VertoAD\Infrastructure\Security\RedisRateLimitStore;
use VertoAD\Infrastructure\Security\TurnstileVerifier;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Storage\DeterministicPresignedUploadSigner;
use VertoAD\Infrastructure\Storage\ObjectStorageUploadSignerInterface;
use VertoAD\Repository\AdSlotRepository;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;
use VertoAD\Repository\Archive\InMemoryArchiveRepository;
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
use VertoAD\Repository\FeatureFlags\FeatureFlagRepositoryInterface;
use VertoAD\Repository\FeatureFlags\InMemoryFeatureFlagRepository;
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
use VertoAD\Repository\OAuthClientRepository;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepository;
use VertoAD\Repository\OAuthConsentRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepository;
use VertoAD\Repository\OAuthTokenRepositoryInterface;
use VertoAD\Repository\Operations\ConfigVersionRepositoryInterface;
use VertoAD\Repository\Operations\DatabaseOperationErrorLogRepository;
use VertoAD\Repository\Operations\InMemoryConfigVersionRepository;
use VertoAD\Repository\Operations\OperationErrorLogRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PasswordResetTokenRepository;
use VertoAD\Repository\PasswordResetTokenRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepository;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepository;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Repository\Review\ReviewRepository;
use VertoAD\Repository\Review\ReviewRepositoryInterface;
use VertoAD\Repository\Reporting\DatabaseReportAggregateRepository;
use VertoAD\Repository\Reporting\InMemoryReportAggregateRepository;
use VertoAD\Repository\Reporting\ReportAggregateRepositoryInterface;
use VertoAD\Repository\SystemConfigRepository;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Repository\Support\InMemorySupportTicketRepository;
use VertoAD\Repository\Support\SupportTicketRepositoryInterface;
use VertoAD\Repository\UserIdentityRepository;
use VertoAD\Repository\UserIdentityRepositoryInterface;
use VertoAD\Repository\Webhooks\DatabaseWebhookDeliveryRepository;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\Assets\AssetUploadService;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveService;
use VertoAD\Service\Archive\ColdQueryService;
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
use VertoAD\Service\Cron\EventConsumptionJob;
use VertoAD\Service\Cron\ExpiredTokenCleanupJob;
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
use VertoAD\Service\Serving\CampaignSpendEligibilityInterface;
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
use VertoAD\Service\SystemConfigService;
use VertoAD\Service\Support\SupportTicketService;
use VertoAD\Service\TenantAccessService;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;
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
                PublisherSiteVerificationService::class => static fn (
                    PublisherSiteRepositoryInterface $repository,
                ): PublisherSiteVerificationService => new PublisherSiteVerificationService($repository),
                AdSlotRepositoryInterface::class => static fn (Connection $connection): AdSlotRepositoryInterface =>
                    new AdSlotRepository($connection),
                AdSlotSetupService::class => static fn (
                    PublisherSiteRepositoryInterface $sites,
                    AdSlotRepositoryInterface $slots,
                ): AdSlotSetupService => new AdSlotSetupService($sites, $slots),
                AssetRepositoryInterface::class => static fn (Connection $connection): AssetRepositoryInterface =>
                    new AssetRepository($connection),
                ObjectStorageUploadSignerInterface::class => static fn (): ObjectStorageUploadSignerInterface =>
                    new DeterministicPresignedUploadSigner($settings['storage']['s3'] ?? []),
                AssetUploadService::class => static fn (
                    AssetRepositoryInterface $repository,
                    ObjectStorageUploadSignerInterface $signer,
                ): AssetUploadService => new AssetUploadService($repository, $signer, $settings['assets'] ?? []),
                ReviewRepositoryInterface::class => static fn (Connection $connection): ReviewRepositoryInterface =>
                    new ReviewRepository($connection),
                CreativeReviewProviderInterface::class => static fn (): CreativeReviewProviderInterface =>
                    self::creativeReviewProvider($settings),
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
                ): CampaignBudgetService => new CampaignBudgetService($budgets, $ledger, $ledgerRepository),
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
                ReportQueryService::class => static fn (
                    ReportAggregateRepositoryInterface $aggregates,
                ): ReportQueryService => new ReportQueryService($aggregates),
                AttributionEventRepositoryInterface::class => static fn (Connection $connection): AttributionEventRepositoryInterface =>
                    new DatabaseAttributionEventRepository($connection),
                AttributionService::class => static fn (
                    AttributionEventRepositoryInterface $events,
                ): AttributionService => new AttributionService(
                    $events,
                    (int) ($settings['attribution']['default_window_seconds'] ?? 604800),
                ),
                ArchiveRepositoryInterface::class => static fn (): ArchiveRepositoryInterface =>
                    new InMemoryArchiveRepository(),
                ArchiveJob::class => static fn (
                    ArchiveRepositoryInterface $repository,
                ): ArchiveJob => new ArchiveJob(
                    $repository,
                    (string) ($settings['archive']['raw_events_base_object_key'] ?? 's3://vertoad-archive/raw-events'),
                ),
                ArchiveService::class => static fn (
                    ArchiveRepositoryInterface $repository,
                ): ArchiveService => new ArchiveService($repository),
                ColdQueryService::class => static fn (
                    ArchiveRepositoryInterface $repository,
                ): ColdQueryService => new ColdQueryService(
                    $repository,
                    (string) ($settings['archive']['query_results_base_object_key'] ?? 's3://vertoad-archive/query-results'),
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
                ConfigVersionRepositoryInterface::class => static fn (): ConfigVersionRepositoryInterface =>
                    new InMemoryConfigVersionRepository(),
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
                WebhookDeliveryJob::class => static fn (
                    WebhookDeliveryRepositoryInterface $deliveries,
                    WebhookSigner $signer,
                ): WebhookDeliveryJob => new WebhookDeliveryJob(
                    $deliveries,
                    $signer,
                    WebhookDeliveryJob::httpTransport((int) ($settings['webhooks']['http_timeout_seconds'] ?? 5)),
                    (int) ($settings['webhooks']['retry_batch_size'] ?? 50),
                ),
                SupportTicketRepositoryInterface::class => static fn (): SupportTicketRepositoryInterface =>
                    new InMemorySupportTicketRepository(),
                SupportTicketService::class => static fn (
                    SupportTicketRepositoryInterface $tickets,
                    AuditLogService $audit,
                ): SupportTicketService => new SupportTicketService($tickets, $audit),
                FeatureFlagRepositoryInterface::class => static fn (): FeatureFlagRepositoryInterface =>
                    new InMemoryFeatureFlagRepository(),
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
                ): AdServingService => new AdServingService($inventory, $candidates, $decisions, $events, $spendEligibility),
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
                ): AdEventBillingService => new AdEventBillingService($budgets, $revenueShare),
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
                ): RechargeKeyService => new RechargeKeyService($repository, $ledger, $cipher),
                SystemConfigRepositoryInterface::class => static fn (Connection $connection): SystemConfigRepositoryInterface =>
                    new SystemConfigRepository($connection),
                SystemConfigService::class => static fn (SystemConfigRepositoryInterface $repository): SystemConfigService =>
                    new SystemConfigService($repository),
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
                CronJobRegistry::class => static function (
                    EventConsumptionJob $eventConsumption,
                    WebhookDeliveryJob $webhookDelivery,
                    ExpiredTokenCleanupJob $expiredTokenCleanup,
                ) use ($settings): CronJobRegistry {
                    $jobs = [$eventConsumption, $webhookDelivery, $expiredTokenCleanup];
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
                CronStatusAction::class => static fn (CronJobRegistry $registry): CronStatusAction => new CronStatusAction($settings, $registry),
                CronRunAction::class => static fn (CronRunner $runner): CronRunAction => new CronRunAction($runner),
                CronAuthMiddleware::class => static fn (): CronAuthMiddleware => new CronAuthMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $settings
                ),
                TurnstileVerifier::class => static fn (): TurnstileVerifier => new TurnstileVerifier(
                    (string) ($settings['turnstile']['secret_key'] ?? ''),
                    (string) ($settings['turnstile']['verify_url'] ?? ''),
                ),
                TurnstileMiddleware::class => static fn (
                    TurnstileVerifier $verifier,
                    AuditLogService $audit,
                ): TurnstileMiddleware => new TurnstileMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $verifier,
                    $audit,
                ),
                RateLimitStoreInterface::class => static fn (): RateLimitStoreInterface =>
                    self::rateLimitStore($settings),
                RateLimiter::class => static fn (RateLimitStoreInterface $store): RateLimiter => new RateLimiter($store),
                RateLimitPolicy::class => static fn (): RateLimitPolicy => new RateLimitPolicy(
                    (int) ($settings['security']['rate_limit']['limit'] ?? 60),
                    (int) ($settings['security']['rate_limit']['window_seconds'] ?? 60),
                ),
                RateLimitMiddleware::class => static fn (
                    RateLimiter $limiter,
                    RateLimitPolicy $policy,
                    AuditLogService $audit,
                ): RateLimitMiddleware => new RateLimitMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $limiter,
                    $policy,
                    $audit,
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
    private static function servingEventRepository(array $settings): AdEventRepositoryInterface
    {
        $env = (string) ($settings['app']['env'] ?? 'local');
        $localFallbackAllowed = in_array($env, ['local', 'test', 'testing'], true);

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
    private static function creativeReviewProvider(array $settings): CreativeReviewProviderInterface
    {
        $config = $settings['ai_review'] ?? [];
        if (is_array($config)
            && (string) ($config['base_url'] ?? '') !== ''
            && (string) ($config['api_key'] ?? '') !== ''
            && (string) ($config['model'] ?? '') !== ''
        ) {
            return new OpenAiCompatibleCreativeReviewProvider($config);
        }

        return new DeterministicCreativeReviewProvider([
            'provider' => 'openai-compatible-deterministic',
            'model' => is_array($config) ? (string) ($config['model'] ?? 'deterministic-v1') : 'deterministic-v1',
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
    private static function redisRequired(array $settings): bool
    {
        $env = (string) ($settings['app']['env'] ?? 'local');

        return !in_array($env, ['local', 'test', 'testing'], true);
    }
}
