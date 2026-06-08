<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Domain\Serving\AdDecision;
use VertoAD\Domain\Serving\AdEvent;
use VertoAD\Infrastructure\Redis\NativeRedisClient;
use VertoAD\Infrastructure\Redis\RedisClientFactory;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Repository\Cron\ServingEventBufferInterface;

final readonly class RedisAdEventRepository implements AdEventRepositoryInterface, ServingEventBufferInterface
{
    private const DEFAULT_VISIBILITY_TIMEOUT_SECONDS = 300;
    private const DEFAULT_EVENT_RETENTION_SECONDS = 604800;
    private RedisClientInterface $client;

    public function __construct(
        RedisClientInterface|\Redis $redis,
        private string $prefix,
        private int $visibilityTimeoutSeconds = self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS,
        private int $eventRetentionSeconds = self::DEFAULT_EVENT_RETENTION_SECONDS,
    ) {
        $this->client = $redis instanceof RedisClientInterface ? $redis : new NativeRedisClient($redis);

        if ($visibilityTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Serving event visibility timeout seconds must be positive.');
        }

        if ($eventRetentionSeconds <= 0) {
            throw new \InvalidArgumentException('Serving event retention seconds must be positive.');
        }
    }

    /** @param array<string, mixed> $settings */
    public static function fromSettings(array $settings): self
    {
        $password = (string) ($settings['password'] ?? '');
        if ($password === '') {
            throw new \RuntimeException('REDIS_PASSWORD is required for serving event buffering.');
        }

        return new self(
            RedisClientFactory::fromSettings($settings),
            (string) ($settings['prefix'] ?? 'vertoad:'),
            (int) ($settings['serving_event_visibility_timeout_seconds'] ?? self::DEFAULT_VISIBILITY_TIMEOUT_SECONDS),
            (int) ($settings['serving_event_retention_seconds'] ?? self::DEFAULT_EVENT_RETENTION_SECONDS),
        );
    }

    public function hasEvent(string $eventType, string $eventId): bool
    {
        return $this->client->exists($this->eventKey($eventType, $eventId));
    }

    public function findEvent(string $eventType, string $eventId): ?AdEvent
    {
        return $this->load($this->eventKey($eventType, $eventId));
    }

    public function hasValidImpression(string $decisionId, string $viewerId): bool
    {
        return $this->hasIndexedEvent($this->validImpressionIndex($decisionId, $viewerId), '-inf', '+inf');
    }

    public function hasRecentValidClick(string $decisionId, string $viewerId, DateTimeImmutable $occurredAt, int $windowSeconds): bool
    {
        $from = $occurredAt->modify('-' . $windowSeconds . ' seconds')->getTimestamp();
        $to = $occurredAt->modify('+' . $windowSeconds . ' seconds')->getTimestamp();

        return $this->hasIndexedEvent($this->validClickIndex($decisionId, $viewerId), (string) $from, (string) $to);
    }

    public function recordImpression(AdDecision $decision, string $eventId, float $visibleRatio, int $visibleMs, DateTimeImmutable $occurredAt): void
    {
        $event = $this->event('impression', $decision, $eventId, $occurredAt, true, null, $visibleRatio, $visibleMs);
        $this->record($event);
        $this->index($this->validImpressionIndex($decision->decisionId, $decision->viewerId), $event);
    }

    public function recordClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt): void
    {
        $event = $this->event('click', $decision, $eventId, $occurredAt, true, null);
        $this->record($event);
        $this->index($this->validClickIndex($decision->decisionId, $decision->viewerId), $event);
    }

    public function recordInvalidClick(AdDecision $decision, string $eventId, DateTimeImmutable $occurredAt, string $reason): void
    {
        $event = $this->event('click', $decision, $eventId, $occurredAt, false, trim($reason));
        $this->record($event);
        $this->index($this->validClickIndex($decision->decisionId, $decision->viewerId), $event);
    }

    public function lease(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Cron event consume batch size must be positive.');
        }

        $now = time();
        $deadline = $now + $this->visibilityTimeoutSeconds;
        $members = $this->client->eval(
            <<<'LUA'
local expired = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1])
for _, member in ipairs(expired) do
    redis.call('ZREM', KEYS[2], member)
    redis.call('ZADD', KEYS[1], ARGV[1], member)
end
local leased = redis.call('ZRANGE', KEYS[1], 0, tonumber(ARGV[2]) - 1)
local claimed = {}
for _, member in ipairs(leased) do
    if redis.call('ZREM', KEYS[1], member) == 1 then
        redis.call('ZADD', KEYS[2], ARGV[3], member)
        table.insert(claimed, member)
    end
end
return claimed
LUA,
            [$this->pendingKey(), $this->processingKey()],
            [(string) $now, (string) $limit, (string) $deadline],
        );

        $events = [];
        foreach (is_array($members) ? $members : [] as $member) {
            $event = $this->load((string) $member);
            if ($event === null) {
                $this->client->zRem($this->processingKey(), (string) $member);
                continue;
            }

            $events[] = $event;
        }

        return $events;
    }

    public function acknowledge(AdEvent $event): void
    {
        $this->client->zRem($this->processingKey(), $this->eventKey($event->eventType, $event->eventId));
    }

    private function record(AdEvent $event): void
    {
        $key = $this->eventKey($event->eventType, $event->eventId);
        $created = $this->client->setNxEx($key, $this->serialize($event), $this->eventRetentionSeconds);
        if (!$created) {
            return;
        }

        $this->client->zAdd($this->pendingKey(), $event->occurredAt->getTimestamp(), $key);
    }

    private function index(string $indexKey, AdEvent $event): void
    {
        if (!$event->valid) {
            return;
        }

        $this->client->zAdd($indexKey, $event->occurredAt->getTimestamp(), $this->eventKey($event->eventType, $event->eventId));
        $this->client->expire($indexKey, $this->eventRetentionSeconds);
    }

    private function hasIndexedEvent(string $indexKey, string $from, string $to): bool
    {
        $members = $this->client->zRangeByScore($indexKey, $from, $to, 0, 1);

        return is_array($members) && $members !== [];
    }

    private function load(string $key): ?AdEvent
    {
        $payload = $this->client->get($key);
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return new AdEvent(
            eventType: (string) $data['event_type'],
            eventId: (string) $data['event_id'],
            decisionId: (string) $data['decision_id'],
            siteId: (int) $data['site_id'],
            slotId: (int) $data['slot_id'],
            viewerId: (string) $data['viewer_id'],
            adId: $data['ad_id'] === null ? null : (string) $data['ad_id'],
            campaignId: $data['campaign_id'] === null ? null : (int) $data['campaign_id'],
            advertiserOrganizationId: $data['advertiser_organization_id'] === null ? null : (int) $data['advertiser_organization_id'],
            publisherOrganizationId: $data['publisher_organization_id'] === null ? null : (int) $data['publisher_organization_id'],
            costPoints: $data['cost_points'] === null ? null : (int) $data['cost_points'],
            occurredAt: new DateTimeImmutable((string) $data['occurred_at'], new DateTimeZone('UTC')),
            valid: (bool) $data['valid'],
            reason: $data['reason'] === null ? null : (string) $data['reason'],
            visibleRatio: $data['visible_ratio'] === null ? null : (float) $data['visible_ratio'],
            visibleMs: $data['visible_ms'] === null ? null : (int) $data['visible_ms'],
        );
    }

    private function serialize(AdEvent $event): string
    {
        return json_encode([
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'decision_id' => $event->decisionId,
            'site_id' => $event->siteId,
            'slot_id' => $event->slotId,
            'viewer_id' => $event->viewerId,
            'ad_id' => $event->adId,
            'campaign_id' => $event->campaignId,
            'advertiser_organization_id' => $event->advertiserOrganizationId,
            'publisher_organization_id' => $event->publisherOrganizationId,
            'cost_points' => $event->costPoints,
            'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'valid' => $event->valid,
            'reason' => $event->reason,
            'visible_ratio' => $event->visibleRatio,
            'visible_ms' => $event->visibleMs,
        ], JSON_THROW_ON_ERROR);
    }

    private function event(
        string $eventType,
        AdDecision $decision,
        string $eventId,
        DateTimeImmutable $occurredAt,
        bool $valid,
        ?string $reason,
        ?float $visibleRatio = null,
        ?int $visibleMs = null,
    ): AdEvent {
        return new AdEvent(
            eventType: $eventType,
            eventId: trim($eventId),
            decisionId: $decision->decisionId,
            siteId: $decision->siteId,
            slotId: $decision->slotId,
            viewerId: $decision->viewerId,
            adId: $decision->adId,
            campaignId: $decision->campaignId,
            advertiserOrganizationId: $decision->advertiserOrganizationId,
            publisherOrganizationId: $decision->publisherOrganizationId,
            costPoints: $eventType === 'impression' ? $decision->impressionCostPoints : $decision->clickCostPoints,
            occurredAt: $occurredAt,
            valid: $valid,
            reason: $reason,
            visibleRatio: $visibleRatio,
            visibleMs: $visibleMs,
        );
    }

    private function eventKey(string $eventType, string $eventId): string
    {
        return $this->prefix . 'serving-events:event:' . hash('sha256', trim($eventType) . ':' . trim($eventId));
    }

    private function pendingKey(): string
    {
        return $this->prefix . 'serving-events:pending';
    }

    private function processingKey(): string
    {
        return $this->prefix . 'serving-events:processing';
    }

    private function validImpressionIndex(string $decisionId, string $viewerId): string
    {
        return $this->prefix . 'serving-events:index:valid-impression:' . hash('sha256', trim($decisionId) . ':' . trim($viewerId));
    }

    private function validClickIndex(string $decisionId, string $viewerId): string
    {
        return $this->prefix . 'serving-events:index:valid-click:' . hash('sha256', trim($decisionId) . ':' . trim($viewerId));
    }
}
