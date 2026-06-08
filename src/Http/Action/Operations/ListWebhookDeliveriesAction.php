<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Repository\Webhooks\WebhookDeliveryRepositoryInterface;

final readonly class ListWebhookDeliveriesAction
{
    public function __construct(private WebhookDeliveryRepositoryInterface $deliveries)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return OperationsJson::write($response, [
            'deliveries' => array_map(static fn ($delivery): array => $delivery->toArray(), $this->deliveries->all()),
        ]);
    }
}
