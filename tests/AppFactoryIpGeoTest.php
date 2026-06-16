<?php

declare(strict_types=1);

namespace VertoAD\Tests {
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use Slim\Psr7\Factory\ResponseFactory;
    use Slim\Psr7\Factory\ServerRequestFactory;
    use VertoAD\AppFactory;
    use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
    use VertoAD\Domain\Serving\AdDecision;
    use VertoAD\Domain\Serving\ServingRequestContext;
    use VertoAD\Http\Action\Serving\ServeFrameAction;
    use VertoAD\Infrastructure\Redis\NativeRedisClient;
    use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
    use VertoAD\Repository\IpGeo\RedisIpGeoRepository;
    use VertoAD\Repository\Serving\InMemoryAdDecisionRepository;
    use VertoAD\Repository\Serving\InMemoryAdEventRepository;
    use VertoAD\Repository\Serving\StaticAdCandidateRepository;
    use VertoAD\Repository\Serving\StaticServingInventoryRepository;
    use VertoAD\Service\IpGeo\AsyncIpGeoResolver;
    use VertoAD\Service\IpGeo\DisabledIpGeoRequestContextResolver;
    use VertoAD\Service\Serving\AdServingService;
    use VertoAD\Service\Serving\NullGeoResolver;

    final class AppFactoryIpGeoTest extends TestCase
    {
        public function testIpGeoRepositoryRequiresRedisPasswordOutsideLocalFallbackEnvironments(): void
        {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for IP geo queue storage.');

            $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'production'],
                'redis' => ['password' => ''],
            ]]);
        }

        public function testIpGeoRepositoryUsesInMemoryFallbackForLocalEnvironmentWithoutRedisPassword(): void
        {
            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'testing'],
                'redis' => ['password' => ''],
            ]]);

            self::assertInstanceOf(InMemoryIpGeoRepository::class, $repository);
        }

        public function testIpGeoRepositoryUsesRedisRepositoryWhenPasswordIsConfigured(): void
        {
            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'production'],
                'redis' => [
                    'driver' => 'predis',
                    'password' => 'redis-secret',
                    'prefix' => 'test:',
                ],
            ]]);

            self::assertInstanceOf(RedisIpGeoRepository::class, $repository);
        }

        public function testGeoResolversSwitchBetweenDisabledAndAsyncImplementations(): void
        {
            $repository = new InMemoryIpGeoRepository();
            $disabled = IpGeoProviderPolicy::fromArray([
                'enabled' => false,
                'include_builtins' => false,
                'queue_source' => 'ops-disabled',
            ]);
            $enabled = IpGeoProviderPolicy::fromArray([
                'enabled' => true,
                'include_builtins' => false,
                'queue_source' => 'ops-enabled',
            ]);

            self::assertInstanceOf(
                NullGeoResolver::class,
                $this->invokeAppFactory('geoResolver', [$repository, $disabled]),
            );
            self::assertInstanceOf(
                AsyncIpGeoResolver::class,
                $this->invokeAppFactory('geoResolver', [$repository, $enabled]),
            );
            self::assertInstanceOf(
                DisabledIpGeoRequestContextResolver::class,
                $this->invokeAppFactory('ipGeoRequestContextResolver', [$repository, $disabled]),
            );
            self::assertInstanceOf(
                AsyncIpGeoResolver::class,
                $this->invokeAppFactory('ipGeoRequestContextResolver', [$repository, $enabled]),
            );
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testNativeRedisClientSetExDelegatesToPhpRedisSetex(): void
        {
            if (!class_exists('Redis')) {
                eval(<<<'PHP'
namespace {
    final class Redis
    {
        /** @var array<string, array{seconds:int,value:string}> */
        public array $setExCalls = [];

        public function setex(string $key, int $seconds, string $value): bool
        {
            $this->setExCalls[$key] = ['seconds' => $seconds, 'value' => $value];

            return true;
        }
    }
}
PHP);
            }

            $redis = new \Redis();
            $client = new NativeRedisClient($redis);

            self::assertTrue($client->setEx('ip-geo:record', '{"ok":true}', 60));
            self::assertSame(
                ['seconds' => 60, 'value' => '{"ok":true}'],
                $redis->setExCalls['ip-geo:record'] ?? null,
            );
        }

        public function testServeFrameReusesRequestServingContextAttribute(): void
        {
            $decisions = new InMemoryAdDecisionRepository();
            $context = new ServingRequestContext('198.51.100.44', 'Frame UA', 'US-CA', null, 'req-frame-context');
            $action = new ServeFrameAction(new AdServingService(
                new StaticServingInventoryRepository([[10, 20]]),
                new StaticAdCandidateRepository([]),
                $decisions,
                new InMemoryAdEventRepository(),
            ));
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/v1/ads/serve?site_id=10&slot_id=20&viewer_id=viewer-frame')
                ->withAttribute(ServingRequestContext::class, $context);

            $response = $action($request, (new ResponseFactory())->createResponse());
            $saved = $this->firstDecision($decisions);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('req-frame-context', $saved->requestId);
            self::assertSame('198.51.100.44', $saved->ipAddress);
            self::assertSame('Frame UA', $saved->userAgent);
            self::assertSame('US-CA', $saved->geoCode);
        }

        /**
         * @param list<mixed> $arguments
         */
        private function invokeAppFactory(string $method, array $arguments): mixed
        {
            $reflection = new ReflectionMethod(AppFactory::class, $method);

            return $reflection->invoke(null, ...$arguments);
        }

        private function firstDecision(InMemoryAdDecisionRepository $repository): AdDecision
        {
            $decisions = $repository->searchDecisions(['limit' => 1]);

            self::assertCount(1, $decisions);

            return $decisions[0];
        }
    }
}
