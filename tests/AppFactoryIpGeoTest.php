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
    use VertoAD\Repository\IpGeo\DatabaseIpGeoRepository;
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
        public function testIpGeoRepositoryUsesDatabaseByDefaultOutsideLocalFallbackEnvironments(): void
        {
            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'production'],
                'redis' => ['password' => ''],
            ], $this->createConnection()]);

            self::assertInstanceOf(DatabaseIpGeoRepository::class, $repository);
        }

        public function testIpGeoRepositoryCanUseExplicitInMemoryFallbackForLocalEnvironment(): void
        {
            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'testing'],
                'ip_geo' => ['repository' => 'memory'],
                'redis' => ['password' => ''],
            ], $this->createConnection()]);

            self::assertInstanceOf(InMemoryIpGeoRepository::class, $repository);
        }

        public function testIpGeoRepositoryUsesImplicitInMemoryFallbackForLocalEnvironmentWhenAdapterIsNotConfigured(): void
        {
            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'testing'],
                'redis' => ['password' => ''],
            ], $this->createConnection()]);

            self::assertInstanceOf(InMemoryIpGeoRepository::class, $repository);
        }

        public function testRealSettingsPreserveUnsetIpGeoRepositoryForLocalTestingFallback(): void
        {
            $previousAppEnv = getenv('APP_ENV');
            $previousIpGeoRepository = getenv('IP_GEO_REPOSITORY');
            putenv('APP_ENV=testing');
            putenv('IP_GEO_REPOSITORY');

            try {
                $settings = require dirname(__DIR__) . '/config/settings.php';
                self::assertNull($settings['ip_geo']['repository'] ?? null);

                $repository = $this->invokeAppFactory('ipGeoRepository', [$settings, $this->createConnection()]);
                self::assertInstanceOf(InMemoryIpGeoRepository::class, $repository);
            } finally {
                $previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousAppEnv);
                $previousIpGeoRepository === false ? putenv('IP_GEO_REPOSITORY') : putenv('IP_GEO_REPOSITORY=' . $previousIpGeoRepository);
            }
        }

        public function testIpGeoRepositoryUsesExplicitRedisRepositoryWhenPasswordIsConfigured(): void
        {
            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'production'],
                'ip_geo' => ['repository' => 'redis'],
                'redis' => [
                    'driver' => 'predis',
                    'password' => 'redis-secret',
                    'prefix' => 'test:',
                ],
            ], $this->createConnection()]);

            self::assertInstanceOf(RedisIpGeoRepository::class, $repository);
        }

        public function testIpGeoRepositoryRejectsUnsafeOrUnknownExplicitAdapters(): void
        {
            foreach (
                [
                    [
                        [
                            'app' => ['env' => 'production'],
                            'ip_geo' => ['repository' => 'memory'],
                            'redis' => ['password' => ''],
                        ],
                        'IP_GEO_REPOSITORY=memory is only allowed in local/testing.',
                    ],
                    [
                        [
                            'app' => ['env' => 'production'],
                            'ip_geo' => ['repository' => 'bogus'],
                            'redis' => ['password' => ''],
                        ],
                        'IP_GEO_REPOSITORY must be one of database, redis, memory.',
                    ],
                    [
                        [
                            'app' => ['env' => 'production'],
                            'ip_geo' => ['repository' => 'redis'],
                            'redis' => ['password' => ''],
                        ],
                        'REDIS_PASSWORD is required for IP geo queue storage.',
                    ],
                ] as [$settings, $message]
            ) {
                try {
                    $this->invokeAppFactory('ipGeoRepository', [$settings, $this->createConnection()]);
                    self::fail('Expected invalid IP geo repository adapter settings to be rejected.');
                } catch (\RuntimeException $exception) {
                    self::assertSame($message, $exception->getMessage());
                }
            }
        }

        public function testIpGeoRepositoryUsesServingGeoPolicyCacheTtlForDatabaseRecords(): void
        {
            $policy = IpGeoProviderPolicy::fromArray([
                'enabled' => true,
                'include_builtins' => false,
                'cache_ttl_seconds' => 4321,
            ]);

            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'production'],
                'redis' => [
                    'driver' => 'predis',
                    'password' => 'redis-secret',
                    'prefix' => 'test:',
                    'ip_geo_record_ttl_seconds' => 9999,
                ],
            ], $this->createConnection(), $policy]);
            $recordTtl = new \ReflectionProperty(DatabaseIpGeoRepository::class, 'recordTtlSeconds');

            self::assertInstanceOf(DatabaseIpGeoRepository::class, $repository);
            self::assertSame(4321, $recordTtl->getValue($repository));
        }

        public function testExplicitRedisIpGeoRepositoryUsesServingGeoPolicyCacheTtlForRedisRecords(): void
        {
            $policy = IpGeoProviderPolicy::fromArray([
                'enabled' => true,
                'include_builtins' => false,
                'cache_ttl_seconds' => 4321,
            ]);

            $repository = $this->invokeAppFactory('ipGeoRepository', [[
                'app' => ['env' => 'production'],
                'ip_geo' => ['repository' => 'redis'],
                'redis' => [
                    'driver' => 'predis',
                    'password' => 'redis-secret',
                    'prefix' => 'test:',
                    'ip_geo_record_ttl_seconds' => 9999,
                ],
            ], $this->createConnection(), $policy]);
            $recordTtl = new \ReflectionProperty(RedisIpGeoRepository::class, 'recordTtlSeconds');

            self::assertInstanceOf(RedisIpGeoRepository::class, $repository);
            self::assertSame(4321, $recordTtl->getValue($repository));
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
        public function testNativeRedisClientDelegatesToPhpRedisScalarCommands(): void
        {
            if (!class_exists('Redis')) {
                eval(<<<'PHP'
namespace {
    final class Redis
    {
        /** @var array<string, array{seconds:int,value:string}> */
        public array $setExCalls = [];
        /** @var list<string> */
        public array $deleteCalls = [];

        public function setex(string $key, int $seconds, string $value): bool
        {
            $this->setExCalls[$key] = ['seconds' => $seconds, 'value' => $value];

            return true;
        }

        public function del(string $key): int
        {
            $this->deleteCalls[] = $key;

            return 1;
        }
    }
}
PHP);
            }

            $redis = new \Redis();
            $client = new NativeRedisClient($redis);

            self::assertTrue($client->setEx('ip-geo:record', '{"ok":true}', 60));
            self::assertSame(1, $client->delete('ip-geo:record'));
            self::assertSame(
                ['seconds' => 60, 'value' => '{"ok":true}'],
                $redis->setExCalls['ip-geo:record'] ?? null,
            );
            self::assertSame(['ip-geo:record'], $redis->deleteCalls);
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

        private function createConnection(): \Doctrine\DBAL\Connection
        {
            return \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        private function firstDecision(InMemoryAdDecisionRepository $repository): AdDecision
        {
            $decisions = $repository->searchDecisions(['limit' => 1]);

            self::assertCount(1, $decisions);

            return $decisions[0];
        }
    }
}
