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

            private function sortedZset(string $key): array
            {
                $members = $this->zsets[$key] ?? [];
                asort($members, SORT_NUMERIC);

                return $members;
            }
        }
    }
}

namespace VertoAD\Tests\Serving {
    use DateTimeImmutable;
    use PHPUnit\Framework\TestCase;
    use VertoAD\Domain\Serving\AdDecision;
    use VertoAD\Repository\Serving\RedisAdEventRepository;

    final class RedisAdEventRepositoryTest extends TestCase
    {
        public function testRecordsEventsDeduplicatesAndSupportsServingGuards(): void
        {
            $redis = new \Redis();
            $repository = new RedisAdEventRepository($redis, 'vertoad:test:', 60, 3600);
            $decision = $this->decision();

            self::assertFalse($repository->hasValidImpression($decision->decisionId, $decision->viewerId));
            self::assertFalse($repository->hasRecentValidClick($decision->decisionId, $decision->viewerId, new DateTimeImmutable('2026-06-08T10:00:30Z'), 30));
            self::assertNull($repository->findEvent('click', 'missing'));

            $repository->recordImpression($decision, 'imp-1', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00Z'));
            $repository->recordImpression($decision, 'imp-1', 0.90, 2500, new DateTimeImmutable('2026-06-08T10:00:01Z'));
            $repository->recordClick($decision, 'clk-1', new DateTimeImmutable('2026-06-08T10:00:20Z'));
            $repository->recordInvalidClick($decision, 'clk-invalid', new DateTimeImmutable('2026-06-08T10:00:25Z'), 'repeat_click_window');

            self::assertTrue($repository->hasEvent('impression', 'imp-1'));
            self::assertTrue($repository->hasValidImpression($decision->decisionId, $decision->viewerId));
            self::assertTrue($repository->hasRecentValidClick($decision->decisionId, $decision->viewerId, new DateTimeImmutable('2026-06-08T10:00:30Z'), 30));

            $invalid = $repository->findEvent('click', 'clk-invalid');
            self::assertNotNull($invalid);
            self::assertFalse($invalid->valid);
            self::assertSame('repeat_click_window', $invalid->reason);

            $leased = $repository->lease(10);
            self::assertSame(['imp-1', 'clk-1', 'clk-invalid'], array_map(static fn ($event): string => $event->eventId, $leased));
        }

        public function testFactoryAppliesRedisConnectionSettings(): void
        {
            $repository = RedisAdEventRepository::fromSettings([
                'driver' => 'phpredis',
                'host' => 'redis.internal',
                'port' => 6380,
                'password' => 'secret',
                'database' => 2,
                'prefix' => 'vertoad:prod:',
                'serving_event_visibility_timeout_seconds' => 30,
                'serving_event_retention_seconds' => 120,
            ]);

            $repository->recordClick($this->decision(), 'clk-factory', new DateTimeImmutable('2026-06-08T10:00:20Z'));

            self::assertSame('clk-factory', $repository->lease(1)[0]->eventId);
        }

        public function testSearchEventsSupportsTypeAndTimeWithoutRequestOrIpFilters(): void
        {
            $repository = new RedisAdEventRepository(new \Redis(), 'vertoad:test:', 60, 3600);
            $decision = $this->decision();

            $repository->recordImpression($decision, 'imp-search', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00Z'));
            $repository->recordInvalidClick($decision, 'clk-search-invalid', new DateTimeImmutable('2026-06-08T10:00:20Z'), 'repeat_click_window');
            $repository->recordClick($decision, 'clk-search-valid', new DateTimeImmutable('2026-06-08T10:01:00Z'));

            $clicks = $repository->searchEvents([
                'event_type' => 'click',
                'occurred_from' => '2026-06-08T10:00:10Z',
                'occurred_to' => '2026-06-08T10:00:40Z',
                'limit' => 10,
            ]);
            $allInRange = $repository->searchEvents([
                'occurred_from' => '2026-06-08T09:59:00Z',
                'occurred_to' => '2026-06-08T10:00:30Z',
                'limit' => 10,
            ]);

            self::assertSame(['clk-search-invalid'], array_map(static fn ($event): string => $event->eventId, $clicks));
            self::assertFalse($clicks[0]->valid);
            self::assertSame(['imp-search', 'clk-search-invalid'], array_map(static fn ($event): string => $event->eventId, $allInRange));
        }

        public function testLeaseUsesProcessingVisibilityAndAckKeepsDedupePayload(): void
        {
            $redis = new \Redis();
            $repository = new RedisAdEventRepository($redis, 'vertoad:test:', 1, 3600);
            $repository->recordClick($this->decision(), 'clk-lease', new DateTimeImmutable('2026-06-08T10:00:20Z'));

            $leased = $repository->lease(1);
            self::assertCount(1, $leased);
            self::assertSame([], $repository->lease(1));

            foreach ($redis->zsets as $key => $members) {
                if (str_ends_with($key, ':processing')) {
                    foreach ($members as $member => $_score) {
                        $redis->zsets[$key][$member] = time() - 1;
                    }
                }
            }

            self::assertSame('clk-lease', $repository->lease(1)[0]->eventId);
            $repository->acknowledge($leased[0]);

            self::assertSame([], $repository->lease(1));
            self::assertTrue($repository->hasEvent('click', 'clk-lease'));
        }

        public function testFailureRequeuesProcessingEventAndDeadLettersAfterMaxAttempts(): void
        {
            $redis = new \Redis();
            $repository = new RedisAdEventRepository($redis, 'vertoad:test:', 60, 3600, 2);
            $repository->recordClick($this->decision(), 'clk-poison', new DateTimeImmutable('2026-06-08T10:00:20Z'));
            $event = $repository->lease(1)[0];

            $repository->fail($event, new \RuntimeException('first failure'));

            $failureKey = null;
            foreach (array_keys($redis->counts) as $key) {
                if (str_ends_with($key, ':failures')) {
                    $failureKey = $key;
                }
            }
            self::assertNotNull($failureKey);
            self::assertSame(1, $redis->counts[$failureKey]);
            self::assertSame(3600, $redis->ttl[$failureKey] ?? null);
            self::assertSame([], $redis->zsets['vertoad:test:serving-events:processing'] ?? []);
            self::assertCount(1, $redis->zsets['vertoad:test:serving-events:pending'] ?? []);

            $event = $repository->lease(1)[0];
            $repository->fail($event, new \RuntimeException('second failure'));

            self::assertSame(2, $redis->counts[$failureKey]);
            self::assertSame([], $redis->zsets['vertoad:test:serving-events:processing'] ?? []);
            self::assertSame([], $redis->zsets['vertoad:test:serving-events:pending'] ?? []);
            self::assertCount(1, $redis->zsets['vertoad:test:serving-events:dead-letter'] ?? []);
        }

        public function testLeaseDropsProcessingMemberWhenPayloadIsMissing(): void
        {
            $redis = new \Redis();
            $repository = new RedisAdEventRepository($redis, 'vertoad:test:', 60, 3600);
            $repository->recordClick($this->decision(), 'clk-missing-payload', new DateTimeImmutable('2026-06-08T10:00:20Z'));

            foreach ($redis->zsets as $members) {
                foreach (array_keys($members) as $member) {
                    unset($redis->values[$member], $redis->keys[$member]);
                }
            }

            self::assertSame([], $repository->lease(1));
            foreach ($redis->zsets as $key => $members) {
                if (str_ends_with($key, ':processing')) {
                    self::assertSame([], $members);
                }
            }
        }

        public function testHydratesNullableBillingMetadata(): void
        {
            $repository = new RedisAdEventRepository(new \Redis(), 'vertoad:test:', 60, 3600);
            $decision = new AdDecision(
                decisionId: 'decision-null',
                siteId: 10,
                slotId: 20,
                viewerId: 'viewer-null',
                filled: true,
                reason: null,
                iframeHtml: '<iframe title="Advertisement"></iframe>',
                width: 300,
                height: 250,
                adId: null,
                campaignId: null,
                advertiserOrganizationId: null,
                publisherOrganizationId: null,
                impressionCostPoints: null,
                clickCostPoints: null,
                landingUrl: null,
                decidedAt: new DateTimeImmutable('2026-06-08T09:59:00Z'),
            );

            $repository->recordImpression($decision, 'imp-null', 0.75, 1500, new DateTimeImmutable('2026-06-08T10:00:00Z'));

            $event = $repository->findEvent('impression', 'imp-null');
            self::assertNotNull($event);
            self::assertNull($event->adId);
            self::assertNull($event->campaignId);
            self::assertNull($event->advertiserOrganizationId);
            self::assertNull($event->publisherOrganizationId);
            self::assertNull($event->costPoints);
        }

        public function testFactoryRequiresRedisExtensionAndPassword(): void
        {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('REDIS_PASSWORD is required for serving event buffering.');

            RedisAdEventRepository::fromSettings(['password' => '']);
        }

        public function testRejectsInvalidLeaseLimit(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Cron event consume batch size must be positive.');

            (new RedisAdEventRepository(new \Redis(), 'vertoad:test:'))->lease(0);
        }

        public function testRejectsInvalidVisibilityTimeoutAndRetention(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Serving event visibility timeout seconds must be positive.');

            new RedisAdEventRepository(new \Redis(), 'vertoad:test:', 0);
        }

        public function testRejectsInvalidRetention(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Serving event retention seconds must be positive.');

            new RedisAdEventRepository(new \Redis(), 'vertoad:test:', 60, 0);
        }

        public function testRejectsInvalidMaxFailures(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Serving event max failures must be positive.');

            new RedisAdEventRepository(new \Redis(), 'vertoad:test:', 60, 3600, 0);
        }

        private function decision(): AdDecision
        {
            return new AdDecision(
                decisionId: 'decision-1',
                siteId: 10,
                slotId: 20,
                viewerId: 'viewer-1',
                filled: true,
                reason: null,
                iframeHtml: '<iframe title="Advertisement"></iframe>',
                width: 300,
                height: 250,
                adId: 'ad-1',
                campaignId: 30,
                advertiserOrganizationId: 40,
                publisherOrganizationId: 50,
                impressionCostPoints: 10,
                clickCostPoints: 20,
                landingUrl: 'https://advertiser.example/landing',
                decidedAt: new DateTimeImmutable('2026-06-08T09:59:00Z'),
            );
        }
    }
}
