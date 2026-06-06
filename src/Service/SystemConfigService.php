<?php

declare(strict_types=1);

namespace VertoAD\Service;

use UnexpectedValueException;
use VertoAD\Repository\SystemConfigRepositoryInterface;

final class SystemConfigService
{
    private const DEFAULT_REVENUE_SHARE_KEY = 'billing.default_revenue_share';
    private const FALLBACK_PUBLISHER_PERCENT = 70;

    public function __construct(private readonly SystemConfigRepositoryInterface $repository)
    {
    }

    public function defaultPublisherRevenueSharePercent(): int
    {
        $config = $this->repository->findLatestValue(self::DEFAULT_REVENUE_SHARE_KEY);

        if ($config === null) {
            return self::FALLBACK_PUBLISHER_PERCENT;
        }

        $publisherPercent = $config['publisher_percent'] ?? null;
        if (!is_int($publisherPercent) || $publisherPercent < 0 || $publisherPercent > 100) {
            throw new UnexpectedValueException('Invalid billing.default_revenue_share publisher_percent.');
        }

        return $publisherPercent;
    }
}
