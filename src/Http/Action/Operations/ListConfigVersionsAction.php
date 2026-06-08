<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\Operations\ConfigVersionService;

final readonly class ListConfigVersionsAction
{
    public function __construct(private ConfigVersionService $configs)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $configKey = (string) ($request->getQueryParams()['config_key'] ?? 'security.rate_limit');
        try {
            $versions = $this->configs->listVersions($configKey);
        } catch (InvalidArgumentException $exception) {
            return OperationsJson::write($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return OperationsJson::write($response, ['versions' => $versions]);
    }
}
