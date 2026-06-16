<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use Closure;
use DateTimeImmutable;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Domain\IpGeo\IpGeoProviderPolicy;
use VertoAD\Repository\IpGeo\IpGeoRepositoryInterface;
use VertoAD\Service\IpGeo\IpGeoProviderSelector;
use VertoAD\Service\IpGeo\MappedHttpIpGeoProviderClient;

final readonly class IpGeoLookupJob implements CronJobInterface
{
    /** @var callable(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param null|callable(): DateTimeImmutable $clock
     */
    public function __construct(
        private IpGeoRepositoryInterface $repository,
        private IpGeoProviderSelector $selector,
        private MappedHttpIpGeoProviderClient $client,
        private IpGeoProviderPolicy $policy,
        ?callable $clock = null,
    ) {
        $this->clock = Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable());
    }

    public function name(): string
    {
        return 'ip-geo-resolve';
    }

    public function run(): CronJobResult
    {
        if (!$this->policy->enabled) {
            return CronJobResult::completed($this->name(), [
                'leased' => 0,
                'resolved' => 0,
                'failed' => 0,
                'disabled' => 1,
            ]);
        }

        $now = ($this->clock)();
        $leased = 0;
        $resolved = 0;
        $failed = 0;

        foreach ($this->repository->leasePending($this->policy->batchSize, $now) as $task) {
            ++$leased;
            $provider = null;
            try {
                $provider = $this->selector->select($task->regionHint, $task->ipAddress);
                $record = $this->client->lookup($task->ipAddress, $provider, ($this->clock)());
                $this->repository->markResolved($record);
                ++$resolved;
            } catch (\Throwable $exception) {
                ++$failed;
                $this->repository->markFailed(
                    $task->ipAddress,
                    $provider?->id,
                    $exception->getMessage(),
                    ($this->clock)(),
                    $this->policy->maxAttempts,
                    $this->policy->retryBackoffSeconds,
                );
            }
        }

        return CronJobResult::completed($this->name(), [
            'leased' => $leased,
            'resolved' => $resolved,
            'failed' => $failed,
        ]);
    }
}
