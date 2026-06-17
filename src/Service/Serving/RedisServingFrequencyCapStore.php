<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Infrastructure\Redis\RedisClientInterface;

final readonly class RedisServingFrequencyCapStore implements ServingFrequencyCapStoreInterface
{
    public function __construct(
        private RedisClientInterface $redis,
        private string $prefix,
        private int $ttlGraceSeconds = 300,
    ) {
    }

    public function servedCount(
        int $campaignId,
        int $slotId,
        string $viewerId,
        string $window,
        DateTimeImmutable $at,
    ): int {
        $value = $this->redis->get($this->key('serve', $campaignId, $slotId, $viewerId, $window, $at));

        return $value === false ? 0 : max(0, (int) $value);
    }

    public function clickCount(
        int $campaignId,
        int $slotId,
        string $viewerId,
        string $window,
        DateTimeImmutable $at,
    ): int {
        $value = $this->redis->get($this->key('click', $campaignId, $slotId, $viewerId, $window, $at));

        return $value === false ? 0 : max(0, (int) $value);
    }

    public function recordServe(
        int $campaignId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $at,
    ): void {
        $this->record('serve', $campaignId, $slotId, $viewerId, $at);
    }

    public function recordClick(
        int $campaignId,
        int $slotId,
        string $viewerId,
        DateTimeImmutable $at,
    ): void {
        $this->record('click', $campaignId, $slotId, $viewerId, $at);
    }

    private function record(string $type, int $campaignId, int $slotId, string $viewerId, DateTimeImmutable $at): void
    {
        foreach (['hour', 'day'] as $window) {
            $key = $this->key($type, $campaignId, $slotId, $viewerId, $window, $at);
            $this->redis->increment($key);
            $this->redis->expire($key, $this->ttlSeconds($window, $at));
        }
    }

    private function key(string $type, int $campaignId, int $slotId, string $viewerId, string $window, DateTimeImmutable $at): string
    {
        $utc = $at->setTimezone(new DateTimeZone('UTC'));
        $bucket = match ($window) {
            'hour' => $utc->format('YmdH'),
            'day' => $utc->format('Ymd'),
            default => throw new \InvalidArgumentException('Serving frequency cap window is not supported.'),
        };
        $viewerHash = hash('sha256', $viewerId);

        return $this->prefix . 'serving:frequency:' . implode(':', [$type, $campaignId, $slotId, $window, $bucket, $viewerHash]);
    }

    private function ttlSeconds(string $window, DateTimeImmutable $at): int
    {
        $utc = $at->setTimezone(new DateTimeZone('UTC'));
        $nextBoundary = match ($window) {
            'hour' => $utc->setTime((int) $utc->format('H'), 0)->modify('+1 hour'),
            'day' => $utc->setTime(0, 0)->modify('+1 day'),
        };

        return max(1, $nextBoundary->getTimestamp() - $utc->getTimestamp() + $this->ttlGraceSeconds);
    }
}
