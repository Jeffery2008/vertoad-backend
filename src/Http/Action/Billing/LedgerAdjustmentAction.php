<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\PointsLedgerService;

final readonly class LedgerAdjustmentAction
{
    private const int IDEMPOTENCY_KEY_MAX_LENGTH = 160;
    private const int REASON_MAX_LENGTH = 240;

    public function __construct(
        private PointsLedgerService $ledger,
        private PointsLedgerRepositoryInterface $entries,
        private AuditLogService $audit,
        private ClientIpResolver $ipResolver,
        private Connection $connection,
    ) {
    }

    public function createAdjustment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $body = $this->body($request);
            $idempotencyKey = $this->boundedStringField($body, 'idempotency_key', self::IDEMPOTENCY_KEY_MAX_LENGTH);
            $existing = $this->entries->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->existingAdjustment($response, $existing, (int) $context->organizationId);
            }

            $entry = $this->connection->transactional(function () use ($body, $context, $idempotencyKey, $request): PointsLedgerEntry {
                $entry = $this->ledger->adjust(
                    organizationId: (int) $context->organizationId,
                    accountType: $this->ledgerAccountTypeField($body),
                    accountId: $this->nullablePositiveIntField($body, 'account_id'),
                    pointsAmount: $this->positiveIntField($body, 'points_amount'),
                    direction: $this->directionField($body),
                    idempotencyKey: $idempotencyKey,
                    reason: $this->boundedStringField($body, 'reason', self::REASON_MAX_LENGTH),
                    actorUserId: $context->user?->id,
                );
                $this->recordAudit($request, $context, $entry, 'billing.ledger.adjust');

                return $entry;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, ['ledger_entry' => BillingSerializers::ledgerEntry($entry)], 201);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function reverseEntry(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $error = BillingRequestGuards::requireAuthenticatedOrganization($context, $request);
        if ($error !== null) {
            return $this->json($response, $error['payload'], $error['status']);
        }

        try {
            $entryId = $this->routeEntryId($args);
            $original = $this->entries->findById($entryId);
            if ($original === null || $original->organizationId !== (int) $context->organizationId) {
                return $this->json($response, [
                    'code' => 'ledger_entry_not_found',
                    'message' => 'Original ledger entry was not found in this organization scope.',
                ], 404);
            }

            $body = $this->body($request);
            $idempotencyKey = $this->boundedStringField($body, 'idempotency_key', self::IDEMPOTENCY_KEY_MAX_LENGTH);
            $existing = $this->entries->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->existingReversal($response, $existing, $original);
            }

            $entry = $this->connection->transactional(function () use ($body, $context, $entryId, $idempotencyKey, $request): PointsLedgerEntry {
                $entry = $this->ledger->reverse(
                    originalEntryId: $entryId,
                    idempotencyKey: $idempotencyKey,
                    reason: $this->boundedStringField($body, 'reason', self::REASON_MAX_LENGTH),
                    actorUserId: $context->user?->id,
                );
                $this->recordAudit($request, $context, $entry, 'billing.ledger.reverse');

                return $entry;
            });
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'Original ledger entry has already been reversed.') {
                return $this->json($response, ['code' => 'ledger_reversal_conflict', 'message' => $exception->getMessage()], 409);
            }

            return $this->json($response, ['code' => 'invalid_request', 'message' => $exception->getMessage()], 422);
        }

        return $this->json($response, ['ledger_entry' => BillingSerializers::ledgerEntry($entry)], 201);
    }

    private function existingAdjustment(ResponseInterface $response, PointsLedgerEntry $entry, int $organizationId): ResponseInterface
    {
        if (
            $entry->organizationId !== $organizationId
            || ($entry->metadata['entry_kind'] ?? null) !== 'adjustment'
            || $entry->referenceType !== 'manual_adjustment'
        ) {
            return $this->json($response, [
                'code' => 'ledger_idempotency_conflict',
                'message' => 'Ledger idempotency key is already used by a different operation.',
            ], 409);
        }

        return $this->json($response, ['ledger_entry' => BillingSerializers::ledgerEntry($entry)], 200);
    }

    private function existingReversal(
        ResponseInterface $response,
        PointsLedgerEntry $entry,
        PointsLedgerEntry $original,
    ): ResponseInterface {
        if (
            $entry->organizationId !== $original->organizationId
            || ($entry->metadata['entry_kind'] ?? null) !== 'reversal'
            || ($entry->metadata['reverses_ledger_entry_id'] ?? null) !== $original->id
        ) {
            return $this->json($response, [
                'code' => 'ledger_idempotency_conflict',
                'message' => 'Ledger idempotency key is already used by a different operation.',
            ], 409);
        }

        return $this->json($response, ['ledger_entry' => BillingSerializers::ledgerEntry($entry)], 200);
    }

    private function recordAudit(
        ServerRequestInterface $request,
        RequestUserContext $context,
        PointsLedgerEntry $entry,
        string $action,
    ): void {
        $this->audit->record(
            action: $action,
            subjectType: 'ledger_entry',
            subjectId: $entry->id,
            actorUserId: $context->user?->id,
            organizationId: $entry->organizationId,
            ipAddress: $this->ipResolver->resolve($request),
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            requestId: RequestIdContext::fromRequest($request),
            metadata: [
                'account_id' => $entry->accountId,
                'account_type' => $entry->accountType,
                'balance_after_points' => $entry->balanceAfterPoints,
                'direction' => $entry->direction->value,
                'entry_metadata' => $entry->metadata,
                'points_amount' => $entry->pointsAmount,
                'reference_id' => $entry->referenceId,
                'reference_type' => $entry->referenceType,
            ],
        );
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

        $value = trim($body[$field]);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function boundedStringField(array $body, string $field, int $maxLength): string
    {
        $value = $this->stringField($body, $field);
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('%s must be at most %d characters.', $field, $maxLength));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function ledgerAccountTypeField(array $body): string
    {
        $accountType = $this->stringField($body, 'account_type');

        return match ($accountType) {
            'advertiser_balance', 'publisher_earnings' => $accountType,
            default => throw new InvalidArgumentException('account_type must be either advertiser_balance or publisher_earnings.'),
        };
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
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $field));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function nullablePositiveIntField(array $body, string $field): ?int
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        return $this->positiveIntField($body, $field);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function directionField(array $body): LedgerDirection
    {
        $direction = $this->stringField($body, 'direction');

        return match ($direction) {
            LedgerDirection::Credit->value => LedgerDirection::Credit,
            LedgerDirection::Debit->value => LedgerDirection::Debit,
            default => throw new InvalidArgumentException('direction must be either credit or debit.'),
        };
    }

    /**
     * @param array<string, mixed> $args
     */
    private function routeEntryId(array $args): int
    {
        $entryId = $args['entry_id'] ?? null;
        if (!is_string($entryId) || !ctype_digit($entryId) || (int) $entryId <= 0) {
            throw new InvalidArgumentException('entry_id must be a positive integer.');
        }

        return (int) $entryId;
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
