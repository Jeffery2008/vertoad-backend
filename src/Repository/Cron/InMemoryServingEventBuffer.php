<?php

declare(strict_types=1);

namespace VertoAD\Repository\Cron;

use VertoAD\Domain\Serving\AdEvent;

final class InMemoryServingEventBuffer implements ServingEventBufferInterface
{
    /** @var list<AdEvent> */
    private array $events;

    /**
     * @param list<AdEvent> $events
     */
    public function __construct(array $events = [])
    {
        $this->events = array_values($events);
    }

    public function lease(int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Cron event consume batch size must be positive.');
        }

        return array_slice($this->events, 0, $limit);
    }

    public function acknowledge(AdEvent $event): void
    {
        $key = $event->eventType . ':' . $event->eventId;
        $this->events = array_values(array_filter(
            $this->events,
            static fn (AdEvent $pending): bool => $pending->eventType . ':' . $pending->eventId !== $key,
        ));
    }

    /**
     * @return list<AdEvent>
     */
    public function pending(): array
    {
        return $this->events;
    }
}
