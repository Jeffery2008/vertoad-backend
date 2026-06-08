<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Cron\CronJobInterface;
use VertoAD\Service\Cron\CronJobRegistry;
use VertoAD\Service\Cron\CronRunner;
use VertoAD\Service\Cron\InMemoryCronLockStore;

final class CronRunnerTest extends TestCase
{
    public function testRunsRegisteredJobBehindLock(): void
    {
        $job = new CountingCronJob('aggregate-statistics');
        $runner = new CronRunner(new CronJobRegistry([$job]), new InMemoryCronLockStore(), 60);

        $result = $runner->run('aggregate-statistics');

        self::assertSame('aggregate-statistics', $result->jobName);
        self::assertTrue($result->acquiredLock);
        self::assertSame('completed', $result->status);
        self::assertSame(1, $job->runs);
        self::assertSame(1, $result->metrics['runs'] ?? null);
    }

    public function testSkipsJobWhenLockIsAlreadyHeld(): void
    {
        $locks = new InMemoryCronLockStore();
        self::assertTrue($locks->acquire('cron:lock:webhook-retry', 60));
        $job = new CountingCronJob('webhook-retry');
        $runner = new CronRunner(new CronJobRegistry([$job]), $locks, 60);

        $result = $runner->run('webhook-retry');

        self::assertSame('locked', $result->status);
        self::assertFalse($result->acquiredLock);
        self::assertSame(0, $job->runs);
    }

    public function testRejectsUnknownJobWithoutTakingLock(): void
    {
        $locks = new InMemoryCronLockStore();
        $runner = new CronRunner(new CronJobRegistry([]), $locks, 60);

        $result = $runner->run('missing-job');

        self::assertSame('not_found', $result->status);
        self::assertFalse($result->acquiredLock);
        self::assertFalse($locks->isLocked('cron:lock:missing-job'));
    }
}

final class CountingCronJob implements CronJobInterface
{
    public int $runs = 0;

    public function __construct(private readonly string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function run(): CronJobResult
    {
        ++$this->runs;

        return CronJobResult::completed($this->name, ['runs' => $this->runs]);
    }
}
