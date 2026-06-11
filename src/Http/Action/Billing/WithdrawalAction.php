<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Service\Billing\WithdrawalProofService;
use VertoAD\Service\Billing\WithdrawalService;

final readonly class WithdrawalAction
{
    public function __construct(
        private WithdrawalService $withdrawals,
        private WithdrawalProofService $proofs,
    ) {
    }

    public function request(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $body = $this->body($request);
            $withdrawal = $this->withdrawals->requestWithdrawal(
                organizationId: (int) $context->organizationId,
                requestedByUserId: (int) $context->user?->id,
                pointsAmount: $this->intField($body, 'points_amount'),
                payoutMethod: $this->stringField($body, 'payout_method'),
                payoutAccount: $this->arrayField($body, 'payout_account'),
                notes: $this->optionalStringField($body, 'notes'),
                now: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->json($response, ['code' => 'withdrawal_rejected', 'message' => $exception->getMessage()], 409);
        }

        return $this->json($response, BillingSerializers::withdrawalRequest($withdrawal), 201);
    }

    public function markPaid(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->transition($request, $response, $args, 'paid');
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->transition($request, $response, $args, 'rejected');
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->transition($request, $response, $args, 'revoked');
    }

    public function resubmit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $body = $this->body($request);
            $withdrawal = $this->withdrawals->resubmit(
                withdrawalRequestId: $this->routeId($args),
                actorUserId: (int) $context->user?->id,
                payoutAccount: $this->arrayField($body, 'payout_account'),
                notes: $this->optionalStringField($body, 'notes'),
                now: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->json($response, ['code' => 'withdrawal_transition_rejected', 'message' => $exception->getMessage()], 409);
        }

        return $this->json($response, BillingSerializers::withdrawalRequest($withdrawal), 200);
    }

    public function createProofIntent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $body = $this->body($request);
            $intent = $this->proofs->createUploadIntent(
                withdrawalRequestId: $this->routeId($args),
                organizationId: (int) $context->organizationId,
                uploadedByUserId: (int) $context->user?->id,
                filename: $this->stringField($body, 'filename'),
                contentType: $this->stringField($body, 'content_type'),
                byteSize: $this->intField($body, 'byte_size'),
                now: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, BillingSerializers::withdrawalProofIntent($intent), 201);
    }

    public function confirmProof(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $body = $this->body($request);
            $proof = $this->proofs->confirmUploadedProof(
                proofId: $this->intField($body, 'proof_id'),
                withdrawalRequestId: $this->routeId($args),
                organizationId: (int) $context->organizationId,
                uploadedByUserId: (int) $context->user?->id,
                objectKey: $this->stringField($body, 'object_key'),
                contentType: $this->stringField($body, 'content_type'),
                byteSize: $this->intField($body, 'byte_size'),
                checksum: $this->stringField($body, 'checksum'),
                now: new DateTimeImmutable(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, BillingSerializers::withdrawalProof($proof), 200);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function transition(ServerRequestInterface $request, ResponseInterface $response, array $args, string $transition): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $body = $this->body($request);
            $notes = $this->optionalStringField($body, 'notes');
            $id = $this->routeId($args);
            $actor = (int) $context->user?->id;
            $now = new DateTimeImmutable();
            if ($transition === 'paid') {
                $withdrawal = $this->withdrawals->markPaid($id, $actor, $notes, $now);
            } elseif ($transition === 'rejected') {
                $withdrawal = $this->withdrawals->reject($id, $actor, $notes, $now);
            } else {
                $withdrawal = $this->withdrawals->revoke($id, $actor, $notes, $now);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return $this->json($response, ['code' => 'withdrawal_transition_rejected', 'message' => $exception->getMessage()], 409);
        }

        return $this->json($response, BillingSerializers::withdrawalRequest($withdrawal), 200);
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
     * @param array<string, mixed> $body
     */
    private function optionalStringField(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        return $this->stringField($body, $field);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $field): int
    {
        if (!array_key_exists($field, $body) || !is_int($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be an integer.', $field));
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function arrayField(array $body, string $field): array
    {
        if (!array_key_exists($field, $body) || !is_array($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be an object.', $field));
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function routeId(array $args): int
    {
        $id = $args['withdrawal_id'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || (int) $id <= 0) {
            throw new InvalidArgumentException('withdrawal_id must be a positive integer.');
        }

        return (int) $id;
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
