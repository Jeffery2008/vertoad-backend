<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

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
                $this->persistence->persist($event);

                $result = $this->billing->billServingEvent($event);
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
