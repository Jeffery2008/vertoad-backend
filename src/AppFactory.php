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
use VertoAD\Repository\SystemConfigRepository;
use VertoAD\Repository\SystemConfigRepositoryInterface;
use VertoAD\Service\SystemConfigService;

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
