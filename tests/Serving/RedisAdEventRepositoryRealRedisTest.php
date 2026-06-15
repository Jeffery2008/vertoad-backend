<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Repository\Serving\RedisAdEventRepository;

#[Group('redis-integration')]
final class RedisAdEventRepositoryRealRedisTest extends TestCase
{
    private string $prefix = '';

    protected function setUp(): void
    {
        if (getenv('VERTOAD_REDIS_INTEGRATION') !== '1') {
            self::markTestSkipped('Set VERTOAD_REDIS_INTEGRATION=1 with Redis 8.8 settings to run this test.');
        }

        $password = (string) getenv('REDIS_PASSWORD');
        if (strlen($password) < 32) {
            self::fail('REDIS_PASSWORD must be a strong 32+ character password for the Redis 8.8 integration harness.');
        }

        $this->prefix = 'vertoad:integration:' . bin2hex(random_bytes(8)) . ':';
    }

    public function testRedisEightEightPasswordProtectedBufferRedeliversAfterVisibilityTimeout(): void
    {
        $repository = RedisAdEventRepository::fromSettings([
            'driver' => getenv('REDIS_DRIVER') ?: 'predis',
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'password' => (string) getenv('REDIS_PASSWORD'),
            'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
            'prefix' => $this->prefix,
            'serving_event_visibility_timeout_seconds' => 1,
            'serving_event_retention_seconds' => 60,
        ]);

        $repository->recordClick($this->decision(), 'clk-real-redis', new DateTimeImmutable('2026-06-08T10:00:20Z'));

        $firstLease = $repository->lease(1);
        self::assertCount(1, $firstLease);
        self::assertSame('clk-real-redis', $firstLease[0]->eventId);
        self::assertSame([], $repository->lease(1));

        sleep(2);

        $redelivered = $repository->lease(1);
        self::assertCount(1, $redelivered);
        self::assertSame('clk-real-redis', $redelivered[0]->eventId);

        $repository->acknowledge($redelivered[0]);
        self::assertSame([], $repository->lease(1));
        self::assertTrue($repository->hasEvent('click', 'clk-real-redis'));
    }

    private function decision(): AdDecision
    {
        return new AdDecision(
            decisionId: 'decision-real-redis',
            siteId: 10,
            slotId: 20,
            viewerId: 'viewer-real-redis',
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
