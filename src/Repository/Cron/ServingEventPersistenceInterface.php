<?php

declare(strict_types=1);

namespace VertoAD\Repository\Cron;

use DateTimeImmutable;
use VertoAD\Domain\Billing\AdEventBillingResult;
use VertoAD\Domain\Serving\AdEvent;

interface ServingEventPersistenceInterface
{
    public function persist(AdEvent $event): bool;

    public function findPendingDuplicate(AdEvent $event): ?AdEvent;

    public function recordBillingResult(AdEvent $event, AdEventBillingResult $result, DateTimeImmutable $processedAt): void;

    public function acknowledge(AdEvent $event): void;

    public function recordFailure(AdEvent $event, \Throwable $reason): void;
}
