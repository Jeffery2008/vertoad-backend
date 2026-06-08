<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

use Predis\Client;

final readonly class PredisCommandClient implements PredisCommandClientInterface
{
    public function __construct(private Client $client)
    {
    }

    public function pipeline(callable $callback): mixed
    {
        return $this->client->pipeline($callback);
    }

    public function __call(string $command, array $arguments): mixed
    {
        return $this->client->{$command}(...$arguments);
    }
}
