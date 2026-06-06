<?php

declare(strict_types=1);

namespace VertoAD;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Middleware\CronAuthMiddleware;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Repository\AdSlotRepository;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\AuditLogRepository;
use VertoAD\Repository\AuditLogRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepository;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepository;
use VertoAD\Repository\PublisherSiteRepositoryInterface;
use VertoAD\Repository\RechargeKeyRepository;
use VertoAD\Repository\RechargeKeyRepositoryInterface;
use VertoAD\Repository\SystemConfigRepository;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Repository\UserIdentityRepository;
use VertoAD\Repository\UserIdentityRepositoryInterface;
use VertoAD\Service\AdSlotSetupService;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\PlaceholderRechargeKeyPlaintextCipher;
use VertoAD\Service\PermissionMatcher;
use VertoAD\Service\PointsLedgerService;
use VertoAD\Service\PublisherSiteVerificationService;
use VertoAD\Service\RechargeKeyPlaintextCipherInterface;
use VertoAD\Service\RechargeKeyService;
use VertoAD\Service\SystemConfigService;
use VertoAD\Service\TenantAccessService;

final class AppFactory
{
    public static function create(?string $basePath = null): App
    {
        $rootPath = $basePath ?? dirname(__DIR__);

        if (is_file($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->safeLoad();
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
                AuditLogRepositoryInterface::class => static fn (Connection $connection): AuditLogRepositoryInterface =>
                    new AuditLogRepository($connection),
                AuditLogService::class => static fn (AuditLogRepositoryInterface $repository): AuditLogService =>
                    new AuditLogService($repository),
                PointsLedgerRepositoryInterface::class => static fn (Connection $connection): PointsLedgerRepositoryInterface =>
                    new PointsLedgerRepository($connection),
                PointsLedgerService::class => static fn (PointsLedgerRepositoryInterface $repository): PointsLedgerService =>
                    new PointsLedgerService($repository),
                RechargeKeyPlaintextCipherInterface::class => static fn (): RechargeKeyPlaintextCipherInterface =>
                    new PlaceholderRechargeKeyPlaintextCipher(),
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
                CronStatusAction::class => static fn (): CronStatusAction => new CronStatusAction($settings),
                CronAuthMiddleware::class => static fn (): CronAuthMiddleware => new CronAuthMiddleware(
                    SlimAppFactory::determineResponseFactory(),
                    $settings
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
        $app->addErrorMiddleware((bool) $settings['app']['debug'], true, true);

        return $app;
    }
}
