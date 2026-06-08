<?php

declare(strict_types=1);

namespace VertoAD\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use VertoAD\Infrastructure\Redis\PredisCommandClient;

final class PredisCommandClientTest extends TestCase
{
    public function testForwardsPipelineAndDynamicCommandsToPredisClient(): void
    {
        $predis = $this->createMock(Client::class);
        $callback = static function (object $pipe): void {
        };

        $predis->expects($this->once())
            ->method('pipeline')
            ->with($callback)
            ->willReturn(['queued']);
        $predis->expects($this->once())
            ->method('__call')
            ->with('get', ['redis-key'])
            ->willReturn('redis-value');

        $client = new PredisCommandClient($predis);

        self::assertSame(['queued'], $client->pipeline($callback));
        self::assertSame('redis-value', $client->get('redis-key'));
    }
}
