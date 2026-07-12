<?php

declare(strict_types=1);

namespace VertoAD\Repository\Webhooks;

use VertoAD\Domain\Webhooks\WebhookDelivery;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Domain\Webhooks\WebhookEvent;

interface WebhookEventDeliveryRepositoryInterface extends WebhookDeliveryRepositoryInterface
{
    public function queueEventForEndpoint(WebhookEndpoint $endpoint, WebhookEvent $event): WebhookDelivery;
}
