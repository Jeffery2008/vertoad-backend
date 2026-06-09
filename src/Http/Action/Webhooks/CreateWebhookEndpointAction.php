<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Webhooks\WebhookEndpoint;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Repository\Webhooks\WebhookEndpointRepositoryInterface;
use VertoAD\Service\Webhooks\WebhookEndpointSecretCipherInterface;

final readonly class CreateWebhookEndpointAction
{
    public function __construct(
        private WebhookEndpointRepositoryInterface $endpoints,
        private WebhookEndpointSecretCipherInterface $secrets,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $guard = CampaignRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($guard !== null) {
            return WebhookEndpointSerializers::json($response, $guard['payload'], $guard['status']);
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return WebhookEndpointSerializers::error($response, 422, 'invalid_request', 'JSON object payload is required.');
        }

        try {
            $name = self::stringField($payload, 'name');
            $endpointUrl = self::stringField($payload, 'endpoint_url');
            WebhookEndpoint::validateEndpointUrl($endpointUrl);
            $events = WebhookEndpoint::normalizeEvents(self::eventsField($payload));
            if ($events === []) {
                throw new InvalidArgumentException('Webhook endpoint requires at least one event.');
            }

            $secret = $this->secrets->generateSigningSecret();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $endpoint = $this->endpoints->store(new WebhookEndpoint(
                id: null,
                endpointId: $this->generateEndpointId(),
                organizationId: (int) $context->organizationId,
                createdByUserId: (int) $context->user?->id,
                name: $name,
                endpointUrl: $endpointUrl,
                status: self::enabledField($payload) ? 'active' : 'paused',
                events: $events,
                encryptedSigningSecret: $this->secrets->encrypt($secret),
                secretPreview: $this->secrets->preview($secret),
                secretRotatedAt: $now,
                createdAt: $now,
                updatedAt: $now,
            ));
        } catch (InvalidArgumentException $exception) {
            return WebhookEndpointSerializers::error($response, 422, 'invalid_webhook_endpoint', $exception->getMessage());
        }

        return WebhookEndpointSerializers::json($response, [
            'endpoint' => WebhookEndpointSerializers::endpoint($endpoint),
            'signing_secret' => $secret,
        ], 201);
    }

    /** @param array<string, mixed> $payload */
    public static function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return trim($value);
    }

    /** @param array<string, mixed> $payload */
    public static function enabledField(array $payload): bool
    {
        $value = $payload['enabled'] ?? true;
        if (!is_bool($value)) {
            throw new InvalidArgumentException('enabled must be a boolean.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function eventsField(array $payload): array
    {
        $value = $payload['events'] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException('events must be an array of strings.');
        }

        $events = array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
        if ($events === []) {
            throw new InvalidArgumentException('Webhook endpoint requires at least one event.');
        }

        return $events;
    }

    private function generateEndpointId(): string
    {
        return 'whe_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
