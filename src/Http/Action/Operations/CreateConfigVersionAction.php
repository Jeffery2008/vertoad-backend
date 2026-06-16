<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Service\Operations\ConfigVersionService;

final readonly class CreateConfigVersionAction
{
    public function __construct(private ConfigVersionService $configs)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $payload = is_array($body) ? $body : [];
        $context = RequestUserContext::fromRequest($request);

        try {
            $version = $this->configs->createVersion(
                (string) ($payload['config_key'] ?? ''),
                is_array($payload['value'] ?? null) ? $payload['value'] : [],
                $context->user?->id ?? 0,
                RequestIdContext::fromRequest($request),
            );
        } catch (InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return OperationsJson::write($response, $version, 201);
    }
}
