<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use DateTimeImmutable;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Repository\Fraud\DatabaseFraudRiskFeatureRepository;

final readonly class FraudFeatureComputeJob implements CronJobInterface
{
    public function __construct(
        private DatabaseFraudRiskFeatureRepository $features,
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
    ) {
        if ($to <= $from) {
            throw new \InvalidArgumentException('Fraud feature compute end time must be after start time.');
        }
    }

    public function name(): string
    {
        return 'fraud-feature-compute';
    }

    public function run(): CronJobResult
    {
        return CronJobResult::completed(
            $this->name(),
            $this->features->refreshFromEvents($this->from, $this->to),
        );
    }
}
