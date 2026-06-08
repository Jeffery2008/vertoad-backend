<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Cron;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Cron\CronJobRegistry;

final class CronStatusAction
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private readonly array $settings,
        private readonly ?CronJobRegistry $registry = null,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'jobs' => $this->registry?->names() ?? $this->settings['cron']['jobs'] ?? [],
            'lock_provider' => 'redis',
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
