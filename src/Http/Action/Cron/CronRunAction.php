<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Cron;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Service\Cron\CronRunner;

final readonly class CronRunAction
{
    public function __construct(private CronRunner $runner)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->runner->run((string) ($args['job_name'] ?? ''));
        $statusCode = $this->statusCode($result);
        $payload = $result->status === 'not_found'
            ? ['code' => 'cron_job_not_found', 'message' => $result->message]
            : [
                'job' => $result->jobName,
                'status' => $result->status,
                'acquired_lock' => $result->acquiredLock,
                'metrics' => $result->metrics,
                'message' => $result->message,
            ];

        $response = $response->withStatus($statusCode);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function statusCode(CronJobResult $result): int
    {
        return match ($result->status) {
            'not_found' => 404,
            default => 200,
        };
    }
}
