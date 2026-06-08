<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

interface RedisSocketConnectionInterface
{
    public function write(string $payload): void;

    public function readLine(): string;

    public function readBytes(int $bytes): string;
}
