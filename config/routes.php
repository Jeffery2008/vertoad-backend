<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Action\HealthAction;
use VertoAD\Http\Middleware\CronAuthMiddleware;

return static function (App $app): void {
    $app->get('/api/v1/health', HealthAction::class);

    $app->group('/api/v1/cron', function (RouteCollectorProxy $group): void {
        $group->get('/status', CronStatusAction::class);
    })->add(CronAuthMiddleware::class);
};
