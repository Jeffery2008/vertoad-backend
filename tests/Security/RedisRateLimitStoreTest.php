<?php

declare(strict_types=1);

namespace {
    if (!class_exists('Redis')) {
        final class Redis
        {
            /** @var array<string, int> */
            public array $keys = [];
            /** @var array<string, string> */
            public array $values = [];
            /** @var array<string, int> */
            public array $counts = [];
            /** @var array<string, int> */
            public array $ttl = [];
            /** @var array<string, array<string, float>> */
            public array $zsets = [];
            /** @var list<array{host: string, port: int, timeout: float}> */
            public array $connections = [];
            public ?string $password = null;
            public ?int $database = null;

            public function connect(string $host, int $port, float $timeout): bool
            {
                $this->connections[] = ['host' => $host, 'port' => $port, 'timeout' => $timeout];

                return true;
            }

            public function auth(string $password): bool
            {
                $this->password = $password;

                return true;
            }

            public function select(int $database): bool
            {
                $this->database = $database;

                return true;
            }

            public function incr(string $key): int
            {
                $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

                return $this->counts[$key];
            }

            public function expire(string $key, int $seconds): bool
            {
                $this->ttl[$key] = $seconds;

                return true;
            }

            /**
             * @param array<string, int|string> $options
             */
            public function set(string $key, string $value, array $options = []): bool
            {
                if (isset($options[0]) && $options[0] === 'nx' && isset($this->keys[$key])) {
                    return false;
                }

                $this->keys[$key] = (int) ($options['ex'] ?? 0);
                $this->values[$key] = $value;

                return true;
            }

            public function get(string $key): string|false
            {
                return $this->values[$key] ?? false;
            }

            public function exists(string $key): int
            {
                return isset($this->keys[$key]) ? 1 : 0;
            }

            public function del(string $key): int
            {
                if (!isset($this->keys[$key])) {
                    return 0;
                }

                unset($this->keys[$key], $this->values[$key], $this->ttl[$key]);

                return 1;
            }

            public function zAdd(string $key, float $score, string $member): int
            {
                $exists = isset($this->zsets[$key][$member]);
                $this->zsets[$key][$member] = $score;

                return $exists ? 0 : 1;
            }

            public function zRem(string $key, string $member): int
            {
                if (!isset($this->zsets[$key][$member])) {
                    return 0;
                }

                unset($this->zsets[$key][$member]);

                return 1;
            }

            /**
             * @param array{limit?: array{0: int, 1: int}} $options
             * @return list<string>
             */
            public function zRangeByScore(string $key, string $from, string $to, array $options = []): array
            {
                $fromScore = $from === '-inf' ? -INF : (float) $from;
                $toScore = $to === '+inf' ? INF : (float) $to;
                $members = [];
                foreach ($this->sortedZset($key) as $member => $score) {
                    if ($score >= $fromScore && $score <= $toScore) {
                        $members[] = $member;
                    }
                }

                if (isset($options['limit'])) {
                    return array_slice($members, $options['limit'][0], $options['limit'][1]);
                }

                return $members;
            }

            /** @return list<string> */
            public function zRange(string $key, int $start, int $end): array
            {
                $members = array_keys($this->sortedZset($key));
                $length = $end < 0 ? null : $end - $start + 1;

                return array_slice($members, $start, $length);
            }

            /**
             * @param list<string> $args
             * @return list<string>
             */
            public function eval(string $script, array $args, int $numKeys): array
            {
                if (str_contains($script, "redis.call('GET', KEYS[1])") && $numKeys === 1) {
                    $key = (string) $args[0];
                    $expectedValue = (string) ($args[1] ?? '');
                    if (($this->values[$key] ?? null) === $expectedValue) {
                        $this->del($key);

                        return [1];
                    }

                    return [0];
                }

                $pending = $args[0];
                $processing = $args[1];
                $now = (float) $args[2];
                $limit = (int) $args[3];
                $deadline = (float) $args[4];

                foreach ($this->zRangeByScore($processing, '-inf', (string) $now) as $member) {
                    $this->zRem($processing, $member);
                    $this->zAdd($pending, $now, $member);
                }

                $claimed = [];
                foreach ($this->zRange($pending, 0, $limit - 1) as $member) {
                    if ($this->zRem($pending, $member) === 1) {
                        $this->zAdd($processing, $deadline, $member);
                        $claimed[] = $member;
                    }
                }

                return $claimed;
            }

            /** @return array<string, float> */
            private function sortedZset(string $key): array
            {
                $members = $this->zsets[$key] ?? [];
                asort($members, SORT_NUMERIC);

                return $members;
            }
        }
    }
}

namespace VertoAD\Tests\Security {
    use PHPUnit\Framework\TestCase;
    use VertoAD\Infrastructure\Security\RedisRateLimitStore;

    final class RedisRateLimitStoreTest extends TestCase
    {
        public function testRedisStoreIncrementsWindowKeyAndSetsTtlOnlyOnFirstHit(): void
        {
            $redis = new \Redis();
            $store = new RedisRateLimitStore($redis, 'vertoad:test:');

            self::assertSame(1, $store->increment('ip=203.0.113.10|endpoint=POST:/login', 1_781_000_000, 60));
            self::assertSame(2, $store->increment('ip=203.0.113.10|endpoint=POST:/login', 1_781_000_000, 60));
            self::assertSame(1, $store->increment('ip=203.0.113.10|endpoint=POST:/login', 1_781_000_060, 60));

            self::assertCount(2, $redis->counts);
            self::assertCount(2, $redis->ttl);
            self::assertContains(60, $redis->ttl);
            foreach (array_keys($redis->counts) as $key) {
                self::assertStringStartsWith('vertoad:test:rate-limit:', $key);
            }
        }

        public function testRedisStoreRejectsInvalidWindowSeconds(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Rate limit window must be at least 1 second.');

            (new RedisRateLimitStore(new \Redis(), 'vertoad:test:'))->increment('bad', 1_781_000_000, 0);
        }

        public function testFactoryAppliesRedisSettings(): void
        {
            $store = RedisRateLimitStore::fromSettings([
                'driver' => 'phpredis',
                'host' => 'redis.internal',
                'port' => 6380,
                'password' => 'secret',
                'database' => 2,
                'prefix' => 'vertoad:prod:',
            ]);

            self::assertSame(1, $store->increment('org=99|endpoint=GET:/reports', 1_781_000_000, 120));
        }
    }
}
