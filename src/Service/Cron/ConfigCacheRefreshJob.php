<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Infrastructure\Redis\RedisClientInterface;
use VertoAD\Repository\SystemConfigRepositoryInterface;

final readonly class ConfigCacheRefreshJob implements CronJobInterface
{
    public function __construct(
        private SystemConfigRepositoryInterface $configs,
        private RedisClientInterface $redis,
        private string $cachePrefix,
        private int $ttlSeconds,
    ) {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Config cache TTL must be positive.');
        }
        if (trim($cachePrefix) === '') {
            throw new \InvalidArgumentException('Config cache prefix is required.');
        }
    }

    public function name(): string
    {
        return 'config-cache-refresh';
    }

    public function run(): CronJobResult
    {
        $refreshed = 0;
        foreach ($this->configs->listLatestValues() as $key => $value) {
            $this->redis->setEx(
                $this->cachePrefix . 'config:' . $key,
                json_encode($value, JSON_THROW_ON_ERROR),
                $this->ttlSeconds,
            );
            ++$refreshed;
        }

        return CronJobResult::completed($this->name(), ['refreshed' => $refreshed]);
    }
}
