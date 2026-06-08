<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Redis;

/**
 * @codeCoverageIgnore
 */
final readonly class ResourceRedisSocketConnection implements RedisSocketConnectionInterface
{
    /** @param resource $socket */
    public function __construct(private mixed $socket)
    {
    }

    public function write(string $payload): void
    {
        while ($payload !== '') {
            $written = @fwrite($this->socket, $payload);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write Redis command.');
            }
            $payload = substr($payload, $written);
        }
    }

    public function readLine(): string
    {
        $line = @fgets($this->socket);
        if ($line === false) {
            throw new \RuntimeException('Unable to read Redis response line.');
        }

        return $line;
    }

    public function readBytes(int $bytes): string
    {
        $payload = '';
        while (strlen($payload) < $bytes) {
            $chunk = @fread($this->socket, $bytes - strlen($payload));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Unable to read Redis response body.');
            }
            $payload .= $chunk;
        }

        return $payload;
    }
}
