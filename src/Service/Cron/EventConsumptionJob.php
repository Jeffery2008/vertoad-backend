<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use DateTimeImmutable;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Repository\Cron\ServingEventBufferInterface;
use VertoAD\Repository\Cron\ServingEventPersistenceInterface;
use VertoAD\Service\Billing\AdEventBillingService;

final readonly class EventConsumptionJob implements CronJobInterface
{
    public function __construct(
        private ServingEventBufferInterface $events,
        private ServingEventPersistenceInterface $persistence,
        private AdEventBillingService $billing,
        private int $batchSize,
    ) {
    }

    public function name(): string
    {
        return 'redis-events-consume';
    }

    public function run(): CronJobResult
    {
        $consumed = 0;
        $billed = 0;
        $skipped = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($this->events->lease($this->batchSize) as $event) {
            ++$consumed;

            try {
                if (!$this->persistence->persist($event)) {
                    $existing = $this->persistence->findPendingDuplicate($event);
                    if ($existing === null) {
                        ++$duplicates;
                        $this->events->acknowledge($event);
                        continue;
                    }

                    ++$duplicates;
                    $result = $this->billing->billServingEvent($existing);
                    $this->persistence->recordBillingResult($existing, $result, new DateTimeImmutable());
                    if ($result->billed) {
                        ++$billed;
                    } else {
                        ++$skipped;
                    }

                    $this->persistence->acknowledge($existing);
                    $this->events->acknowledge($event);
                    continue;
                }

                $result = $this->billing->billServingEvent($event);
                $this->persistence->recordBillingResult($event, $result, new DateTimeImmutable());
                if ($result->billed) {
                    ++$billed;
                    if ($result->duplicate) {
                        ++$duplicates;
                    }
                } else {
                    ++$skipped;
                }

                $this->persistence->acknowledge($event);
                $this->events->acknowledge($event);
            } catch (\Throwable $exception) {
                ++$failed;
                try {
                    $this->persistence->recordFailure($event, $exception);
                } catch (\Throwable) {
                }
                $this->events->fail($event, $exception);
            }
        }

        return CronJobResult::completed($this->name(), [
            'consumed' => $consumed,
            'billed' => $billed,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'failed' => $failed,
        ]);
    }
}
