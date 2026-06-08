<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Archive;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Archive\ColdQueryService;

final readonly class GetColdQueryAction
{
    public function __construct(private ColdQueryService $queries)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $job = $this->queries->find($args['job_id'] ?? '');

        return $job === null
            ? $this->json($response, ['code' => 'not_found', 'message' => 'Cold query job not found.'], 404)
            : $this->json($response, ArchiveSerializers::coldQuery($job), 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
