<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Permissions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Service\PermissionInventory;

final class PermissionInventoryAction
{
    public function __construct(private readonly PermissionInventory $inventory)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode($this->inventory->all(), JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
