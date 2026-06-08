<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\RechargeKeyService;

final readonly class RechargeKeyRedeemAction
{
    public function __construct(private RechargeKeyService $rechargeKeys)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        $body = $this->body($request);

        try {
            $redemption = $this->rechargeKeys->redeem(
                plaintextKey: $this->stringField($body, 'key'),
                organizationId: (int) $context->organizationId,
                redeemedByUserId: (int) $context->user?->id,
                now: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->json($response, ['code' => 'recharge_key_rejected', 'message' => $exception->getMessage()], 409);
        }

        return $this->json($response, [
            'id' => $redemption->key->id,
            'organization_id' => $redemption->key->organizationId,
            'points_amount' => $redemption->key->pointsAmount,
            'status' => $redemption->key->status->value,
            'ledger_entry' => BillingSerializers::ledgerEntry($redemption->ledgerEntry),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $field): string
    {
        if (!array_key_exists($field, $body) || !is_string($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        return $body[$field];
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
