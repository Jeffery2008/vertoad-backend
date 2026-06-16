<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\Operations\ConfigVersionService;

final readonly class RollbackConfigVersionAction
{
    public function __construct(private ConfigVersionService $configs)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        try {
            $version = $this->configs->rollback(
                $args['version_id'] ?? '',
                $context->user?->id ?? 0,
                RequestIdContext::fromRequest($request),
            );
        } catch (RuntimeException $exception) {
            return OperationsJson::write($response, ['code' => 'not_found', 'message' => $exception->getMessage()], 404);
        }

        return OperationsJson::write($response, $version);
    }
}
