<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Routing\RouteCollectorProxy;
use VertoAD\Http\Action\Cron\CronRunAction;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Middleware\ApiEnvelopeMiddleware;
use VertoAD\Http\Middleware\CronAuthMiddleware;
use VertoAD\Service\Cron\CronJobRegistry;
use VertoAD\Service\Cron\CronRunner;
use VertoAD\Service\Cron\InMemoryCronLockStore;
use VertoAD\Service\Cron\NoOpCronJob;

final class CronRouteIntegrationTest extends TestCase
{
    public function testRunsCronJobWithStandardEnvelope(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/jobs/ai-review-queue/run', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Cron-Token', 'test-cron-token')
            ->withHeader('X-Request-Id', 'req-cron-run');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('req-cron-run', $payload['request_id'] ?? null);
        self::assertSame('ai-review-queue', $payload['data']['job'] ?? null);
        self::assertSame('completed', $payload['data']['status'] ?? null);
        self::assertTrue($payload['data']['acquired_lock'] ?? null);
    }

    public function testUnknownCronJobReturnsNotFoundEnvelope(): void
    {
        $app = $this->createApp();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/jobs/missing/run', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Cron-Token', 'test-cron-token');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('cron_job_not_found', $payload['error']['code'] ?? null);
    }

    public function testLockedCronJobReturnsEnvelopeWithoutRunningAgain(): void
    {
        $locks = new InMemoryCronLockStore();
        self::assertTrue($locks->acquire('cron:lock:ai-review-queue', 60));
        $app = $this->createApp($locks);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/cron/jobs/ai-review-queue/run', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Cron-Token', 'test-cron-token');

        $response = $app->handle($request);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ai-review-queue', $payload['data']['job'] ?? null);
        self::assertSame('locked', $payload['data']['status'] ?? null);
        self::assertFalse($payload['data']['acquired_lock'] ?? true);
    }

    private function createApp(?InMemoryCronLockStore $locks = null): \Slim\App
    {
        $settings = [
            'cron' => [
                'token' => 'test-cron-token',
                'allowed_ips' => ['127.0.0.1'],
                'jobs' => ['ai-review-queue'],
            ],
        ];
        $registry = new CronJobRegistry([new NoOpCronJob('ai-review-queue')]);
        $runner = new CronRunner($registry, $locks ?? new InMemoryCronLockStore(), 60);
        $container = (new ContainerBuilder())->addDefinitions([
            CronStatusAction::class => static fn (): CronStatusAction => new CronStatusAction($settings, $registry),
            CronRunAction::class => static fn (): CronRunAction => new CronRunAction($runner),
            CronAuthMiddleware::class => static fn (): CronAuthMiddleware => new CronAuthMiddleware(
                SlimAppFactory::determineResponseFactory(),
                $settings,
            ),
        ])->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();
        $app->group('/api/v1/cron', function (RouteCollectorProxy $group): void {
            $group->get('/status', CronStatusAction::class);
            $group->get('/jobs/{job_name}/run', CronRunAction::class);
        })->add(CronAuthMiddleware::class);
        $app->add(new ApiEnvelopeMiddleware($app->getResponseFactory()));
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, true, true);

        return $app;
    }
}
