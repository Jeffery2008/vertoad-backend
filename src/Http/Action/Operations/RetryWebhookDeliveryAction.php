<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Operations;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Service\Webhooks\WebhookDeliveryJob;

final readonly class RetryWebhookDeliveryAction
{
    public function __construct(private WebhookDeliveryJob $deliveries)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $delivery = $this->deliveries->retry($args['delivery_id'] ?? '');
        } catch (RuntimeException $exception) {
            return OperationsJson::write($response, ['code' => 'not_found', 'message' => $exception->getMessage()], 404);
        }

        return OperationsJson::write($response, $delivery->toArray());
    }
}
