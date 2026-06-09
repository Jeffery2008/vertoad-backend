<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use DateTimeImmutable;
use DateTimeZone;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Repository\OAuthTokenRepositoryInterface;

final readonly class ExpiredTokenCleanupJob implements CronJobInterface
{
    /** @param (callable(): DateTimeImmutable)|null $clock */
    public function __construct(
        private OAuthTokenRepositoryInterface $tokens,
        private int $retentionSeconds,
        private mixed $clock = null,
    ) {
        if ($retentionSeconds < 0) {
            throw new \InvalidArgumentException('Expired token cleanup retention seconds must be non-negative.');
        }
    }

    public function name(): string
    {
        return 'expired-token-cleanup';
    }

    public function run(): CronJobResult
    {
        return CronJobResult::completed(
            $this->name(),
            $this->tokens->cleanupExpiredTokens($this->now(), $this->retentionSeconds),
            'Expired OAuth authorization codes, access tokens, and refresh tokens were cleaned.',
        );
    }

    private function now(): DateTimeImmutable
    {
        if (is_callable($this->clock)) {
            return ($this->clock)();
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
