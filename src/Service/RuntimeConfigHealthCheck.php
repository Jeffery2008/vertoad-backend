<?php

declare(strict_types=1);

namespace VertoAD\Service;

final readonly class RuntimeConfigHealthCheck
{
    private \Closure $check;

    public function __construct(callable $check)
    {
        $this->check = \Closure::fromCallable($check);
    }

    public static function fromSystemConfig(SystemConfigService $configs): self
    {
        return new self(static function () use ($configs): void {
            $configs->assetUploadPolicy();
            $configs->attributionDefaultWindowSeconds();
            $configs->rateLimitPolicy();
            $configs->servingEventPolicy();
        });
    }

    public function assertHealthy(): void
    {
        ($this->check)();
    }
}
