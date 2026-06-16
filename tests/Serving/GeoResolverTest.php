<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Serving\ServingGeoTargetingPolicy;
use VertoAD\Infrastructure\Redis\InMemoryRedisClient;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Service\Serving\GeoProviderInterface;
use VertoAD\Service\Serving\GeoResolver;
use VertoAD\Service\Serving\PconlineGeoProvider;

final class GeoResolverTest extends TestCase
{
    public function testPconlineProviderParsesChineseProvinceResponseIntoCanonicalGeoCode(): void
    {
        $calls = [];
        $provider = new PconlineGeoProvider(
            new ServingGeoTargetingPolicy(enabled: true, timeoutSeconds: 3),
            static function (string $url, int $timeoutSeconds) use (&$calls): array {
                $calls[] = [$url, $timeoutSeconds];

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'ip' => '203.0.113.8',
                        'pro' => '上海市',
                        'city' => '上海市',
                    ], JSON_THROW_ON_ERROR),
                ];
            },
        );

        self::assertSame('CN-SH', $provider->lookup('203.0.113.8', 'UA'));
        self::assertSame(1, count($calls));
        self::assertStringContainsString('ip=203.0.113.8', $calls[0][0]);
        self::assertSame(3, $calls[0][1]);
    }

    public function testGeoResolverCachesProviderResultsByIp(): void
    {
        $providerCalls = 0;
        $provider = new PconlineGeoProvider(
            new ServingGeoTargetingPolicy(enabled: true),
            static function () use (&$providerCalls): array {
                ++$providerCalls;

                return ['status' => 200, 'body' => '{"pro":"北京","city":"北京"}'];
            },
        );
        $cache = new InMemoryRedisClient();
        $resolver = new GeoResolver($provider, $cache, 'vertoad:test:geo:', 3600);

        $first = $resolver->contextForRequest('198.51.100.10', 'UA');
        $second = $resolver->contextForRequest('198.51.100.10', 'UA');

        self::assertSame('CN-BJ', $first->geoCode);
        self::assertSame('CN-BJ', $second->geoCode);
        self::assertSame('198.51.100.10', $first->ipAddress);
        self::assertSame('UA', $first->userAgent);
        self::assertSame(1, $providerCalls);
    }

    public function testGeoResolverIgnoresInvalidIpWithoutCallingProvider(): void
    {
        $providerCalls = 0;
        $provider = new PconlineGeoProvider(
            new ServingGeoTargetingPolicy(enabled: true),
            static function () use (&$providerCalls): array {
                ++$providerCalls;

                return ['status' => 200, 'body' => '{"pro":"上海"}'];
            },
        );

        $resolver = new GeoResolver($provider, new InMemoryRedisClient(), 'vertoad:test:geo:', 3600);

        self::assertNull($resolver->resolve('not-an-ip'));
        self::assertSame(0, $providerCalls);
    }

    public function testGeoResolverFailsOpenWhenProviderThrows(): void
    {
        $provider = new class implements GeoProviderInterface {
            public function lookup(?string $ipAddress, ?string $userAgent = null): ?string
            {
                throw new \RuntimeException('provider unavailable');
            }
        };
        $resolver = new GeoResolver($provider, new InMemoryRedisClient(), 'vertoad:test:geo:', 3600);

        $context = $resolver->contextForRequest('203.0.113.8', 'UA');

        self::assertSame('203.0.113.8', $context->ipAddress);
        self::assertSame('UA', $context->userAgent);
        self::assertNull($context->geoCode);
    }

    public function testGeoResolverFailsOpenWhenCacheReadThrows(): void
    {
        $providerCalls = 0;
        $provider = new class($providerCalls) implements GeoProviderInterface {
            public function __construct(private int &$providerCalls)
            {
            }

            public function lookup(?string $ipAddress, ?string $userAgent = null): ?string
            {
                ++$this->providerCalls;

                return 'CN-SH';
            }
        };
        $cache = new class implements RedisClientInterface {
            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): int
            {
                return 0;
            }

            public function deleteIfValue(string $key, string $expectedValue): bool
            {
                return false;
            }

            public function expire(string $key, int $seconds): bool
            {
                return false;
            }

            public function get(string $key): string|false
            {
                throw new \RuntimeException('cache unavailable');
            }

            public function increment(string $key): int
            {
                return 1;
            }

            public function setNxEx(string $key, string $value, int $seconds): bool
            {
                return false;
            }

            public function setEx(string $key, string $value, int $seconds): bool
            {
                return false;
            }

            public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
            {
                return [];
            }

            public function zAdd(string $key, float $score, string $member): int
            {
                return 0;
            }

            public function zRem(string $key, string $member): int
            {
                return 0;
            }

            public function eval(string $script, array $keys, array $arguments): array
            {
                return [];
            }
        };
        $resolver = new GeoResolver($provider, $cache, 'vertoad:test:geo:', 3600);

        self::assertNull($resolver->resolve('203.0.113.8'));
        self::assertSame(0, $providerCalls);
    }

    public function testGeoResolverKeepsProviderResultWhenCacheWriteThrows(): void
    {
        $provider = new class implements GeoProviderInterface {
            public function lookup(?string $ipAddress, ?string $userAgent = null): ?string
            {
                return 'CN-SH';
            }
        };
        $cache = new class implements RedisClientInterface {
            public function exists(string $key): bool
            {
                return false;
            }

            public function delete(string $key): int
            {
                return 0;
            }

            public function deleteIfValue(string $key, string $expectedValue): bool
            {
                return false;
            }

            public function expire(string $key, int $seconds): bool
            {
                return false;
            }

            public function get(string $key): string|false
            {
                return false;
            }

            public function increment(string $key): int
            {
                return 1;
            }

            public function setNxEx(string $key, string $value, int $seconds): bool
            {
                return false;
            }

            public function setEx(string $key, string $value, int $seconds): bool
            {
                throw new \RuntimeException('cache write unavailable');
            }

            public function zRangeByScore(string $key, string $from, string $to, int $offset, int $count): array
            {
                return [];
            }

            public function zAdd(string $key, float $score, string $member): int
            {
                return 0;
            }

            public function zRem(string $key, string $member): int
            {
                return 0;
            }

            public function eval(string $script, array $keys, array $arguments): array
            {
                return [];
            }
        };
        $resolver = new GeoResolver($provider, $cache, 'vertoad:test:geo:', 3600);

        self::assertSame('CN-SH', $resolver->resolve('203.0.113.8'));
    }

    public function testPconlineProviderRejectsProviderFailures(): void
    {
        $provider = new PconlineGeoProvider(
            new ServingGeoTargetingPolicy(enabled: true),
            static fn (): array => ['status' => 500, 'body' => ''],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Geo provider request failed.');

        $provider->lookup('203.0.113.8');
    }
}
