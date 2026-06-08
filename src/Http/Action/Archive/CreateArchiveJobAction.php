<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Archive;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveService;

final readonly class CreateArchiveJobAction
{
    public function __construct(
        private ArchiveJob $job,
        private ArchiveService $archive,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->job->run();
        $manifest = $this->archive->manifest((string) $result->metrics['manifest_id']);

        return $this->json($response, ArchiveSerializers::manifest($manifest), 202);
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
