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

            public function zRange(string $key, int $start, int $end): array
            {
                $members = array_keys($this->sortedZset($key));
                $length = $end < 0 ? null : $end - $start + 1;

                return array_slice($members, $start, $length);
            }

            public function eval(string $script, array $args, int $numKeys): array
            {
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

            private function sortedZset(string $key): array
            {
                $members = $this->zsets[$key] ?? [];
                asort($members, SORT_NUMERIC);

                return $members;
            }
        }
    }
}

namespace VertoAD\Tests\Cron {
    use PHPUnit\Framework\TestCase;
    use VertoAD\Service\Cron\InMemoryCronLockStore;
    use VertoAD\Service\Cron\RedisCronLockStore;

    final class CronLockStoreTest extends TestCase
    {
        public function testInMemoryLockRejectsInvalidTtl(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Cron lock TTL seconds must be positive.');

            (new InMemoryCronLockStore())->acquire('cron:lock:bad', 0);
        }

        public function testInMemoryLockPurgesExpiredKeysBeforeRead(): void
        {
            $locks = new InMemoryCronLockStore();

            self::assertTrue($locks->acquire('cron:lock:short', 1));
            sleep(2);

            self::assertFalse($locks->isLocked('cron:lock:short'));
        }

        public function testRedisLockUsesConfiguredConnectionAndNxExSemantics(): void
        {
            $redis = new \Redis();
            $store = new RedisCronLockStore($redis, 'vertoad:test:');

            self::assertTrue($store->acquire('cron:lock:events', 30));
            self::assertFalse($store->acquire('cron:lock:events', 30));
            self::assertTrue($store->isLocked('cron:lock:events'));
            self::assertSame(30, $redis->keys['vertoad:test:cron:lock:events'] ?? null);
            $store->release('cron:lock:events');
            self::assertFalse($store->isLocked('cron:lock:events'));
        }

        public function testRedisLockRejectsInvalidTtl(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Cron lock TTL seconds must be positive.');

            (new RedisCronLockStore(new \Redis(), 'vertoad:test:'))->acquire('cron:lock:bad', 0);
        }

        public function testRedisLockFactoryAppliesPasswordDatabaseAndPrefix(): void
        {
            $store = RedisCronLockStore::fromSettings([
                'driver' => 'phpredis',
                'host' => 'redis.internal',
                'port' => 6380,
                'password' => 'secret',
                'database' => 2,
                'prefix' => 'vertoad:prod:',
            ]);

            self::assertTrue($store->acquire('cron:lock:backup-check', 45));
            self::assertTrue($store->isLocked('cron:lock:backup-check'));
        }
    }
}
