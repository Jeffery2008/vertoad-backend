<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Service\RechargeKeyService;

final readonly class GenerateRechargeKeyBatchAction
{
    public function __construct(
        private RechargeKeyService $rechargeKeys,
        private ClientIpResolver $ipResolver,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'Authentication is required for this endpoint.',
            ], 401);
        }

        $body = $this->body($request);

        try {
            $keys = $this->rechargeKeys->generateBatch(
                pointsAmount: $this->positiveIntField($body, 'points_amount'),
                count: $this->positiveIntField($body, 'count'),
                batchCode: $this->nullableStringField($body, 'batch_code'),
                batchMetadata: $this->nullableObjectField($body, 'batch_metadata'),
                expiresAt: $this->nullableDateTimeField($body, 'expires_at'),
                issuedByUserId: (int) $context->user->id,
                ipAddress: $this->ipResolver->resolve($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->json($response, ['code' => 'recharge_key_generation_failed', 'message' => $exception->getMessage()], 409);
        }

        return $this->json($response, [
            'batch_code' => $this->nullableStringField($body, 'batch_code'),
            'count' => count($keys),
            'keys' => array_map(
                static fn ($generated): array => BillingSerializers::rechargeKey($generated->key, $generated->plaintextKey),
                $keys,
            ),
        ], 201);
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
    private function positiveIntField(array $body, string $field): int
    {
        if (!array_key_exists($field, $body)) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $field));
        }

        $value = $body[$field];
        if (is_int($value)) {
            if ($value > 0) {
                return $value;
            }
        } elseif (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $field));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function nullableStringField(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }

        if (!is_string($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be a string when provided.', $field));
        }

        $value = trim($body[$field]);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function nullableObjectField(array $body, string $field): ?array
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }

        if (!is_array($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be an object when provided.', $field));
        }

        if (array_is_list($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be an object when provided.', $field));
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function nullableDateTimeField(array $body, string $field): ?DateTimeImmutable
    {
        $value = $this->nullableStringField($body, $field);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('%s must be a valid datetime string.', $field), previous: $exception);
        }
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
