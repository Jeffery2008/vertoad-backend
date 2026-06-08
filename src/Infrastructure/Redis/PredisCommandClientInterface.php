<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

interface PredisCommandClientInterface
{
    /**
     * @param callable(object): void $callback
     */
    public function pipeline(callable $callback): mixed;

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $command, array $arguments): mixed;
}
