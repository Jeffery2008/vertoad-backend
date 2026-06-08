<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Archive;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Archive\ArchiveService;

final readonly class ArchiveManifestAction
{
    public function __construct(private ArchiveService $archive)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $manifest = $this->archive->manifest($args['manifest_id'] ?? '');

        return $manifest === null
            ? $this->json($response, ['code' => 'not_found', 'message' => 'Archive manifest not found.'], 404)
            : $this->json($response, ArchiveSerializers::manifest($manifest), 200);
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
