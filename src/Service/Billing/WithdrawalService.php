<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Billing\WithdrawalPaymentStatus;
use VertoAD\Domain\Billing\WithdrawalProofStatus;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Domain\Billing\WithdrawalReviewStatus;
use VertoAD\Domain\Ledger\LedgerDirection;
use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Service\PointsLedgerService;

final class WithdrawalService
{
    private const int IDEMPOTENCY_KEY_MAX_LENGTH = 160;
    private const int NOTES_MAX_LENGTH = 2_000;
    private const int PAYOUT_ACCOUNT_MAX_BYTES = 8_192;
    private const int POINTS_PER_CNY = 100;

    public function __construct(
        private readonly WithdrawalRepository $repository,
        private readonly PointsLedgerService $ledger,
        private readonly PointsLedgerRepositoryInterface $ledgerRepository,
    ) {
    }

    /** @param array<string, mixed> $payoutAccount */
    public function requestWithdrawal(
        int $organizationId,
        int $requestedByUserId,
        int $pointsAmount,
        string $payoutMethod,
        array $payoutAccount,
        ?string $notes,
        string $idempotencyKey,
        DateTimeImmutable $now,
    ): WithdrawalRequest {
        $payoutMethod = $this->normalizePayoutMethod($payoutMethod);
        $payoutAccount = $this->normalizePayoutAccount($payoutAccount);
        $notes = $this->normalizeNotes($notes, 'applicant_notes');
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $this->validateRequest($organizationId, $requestedByUserId, $pointsAmount);

        return $this->repository->transactional(function () use (
            $organizationId,
            $requestedByUserId,
            $pointsAmount,
            $payoutMethod,
            $payoutAccount,
            $notes,
            $idempotencyKey,
            $now,
        ): WithdrawalRequest {
            $this->repository->lockOrganizationForUpdate($organizationId);
            $existing = $this->repository->findRequestByIdempotencyKey($organizationId, $idempotencyKey);
            if ($existing !== null) {
                $this->assertSameIdempotentRequest(
                    $existing,
                    $requestedByUserId,
                    $pointsAmount,
                    $payoutMethod,
                    $payoutAccount,
                    $notes,
                );

                return $existing;
            }

            $ledgerEntry = $this->holdPublisherEarnings(
                organizationId: $organizationId,
                pointsAmount: $pointsAmount,
                idempotencyKey: $this->ledgerIdempotencyKey('withdrawal-request', $organizationId, $idempotencyKey),
                referenceType: 'withdrawal_request',
                referenceId: null,
                memo: 'Publisher withdrawal hold',
                metadata: ['idempotency_key' => $idempotencyKey, 'requested_by_user_id' => $requestedByUserId],
            );

            $request = $this->repository->createRequest(
                organizationId: $organizationId,
                requestedByUserId: $requestedByUserId,
                pointsAmount: $pointsAmount,
                amountCny: $this->amountCny($pointsAmount),
                pointsPerCny: self::POINTS_PER_CNY,
                idempotencyKey: $idempotencyKey,
                payoutMethod: $payoutMethod,
                payoutAccount: $payoutAccount,
                notes: $notes,
                ledgerEntryId: (int) $ledgerEntry->id,
                now: $now,
            );
            $this->audit(
                request: $request,
                actorUserId: $requestedByUserId,
                action: 'requested',
                fromReviewStatus: null,
                toReviewStatus: WithdrawalReviewStatus::Pending,
                fromPaymentStatus: null,
                toPaymentStatus: WithdrawalPaymentStatus::NotStarted,
                proofId: null,
                notes: $notes,
                metadata: null,
                now: $now,
            );

            return $request;
        });
    }

    public function approve(
        int $withdrawalRequestId,
        int $actorUserId,
        ?string $notes,
        DateTimeImmutable $now,
    ): WithdrawalRequest {
        $this->validateTransitionIdentity($withdrawalRequestId, $actorUserId);
        $notes = $this->normalizeNotes($notes, 'reviewer_notes');

        return $this->repository->transactional(function () use ($withdrawalRequestId, $actorUserId, $notes, $now): WithdrawalRequest {
            $request = $this->repository->findRequestForUpdate($withdrawalRequestId);
            if ($request === null) {
                throw new RuntimeException('withdrawal_not_found');
            }

            if ($request->reviewStatus === WithdrawalReviewStatus::Approved) {
                return $request;
            }

            if (
                $request->reviewStatus !== WithdrawalReviewStatus::Pending
                || $request->paymentStatus !== WithdrawalPaymentStatus::NotStarted
            ) {
                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $updated = $this->repository->approveIfPending($withdrawalRequestId, $actorUserId, $notes, $now);
            if ($updated === null) {
                $current = $this->repository->findRequest($withdrawalRequestId);
                if ($current?->reviewStatus === WithdrawalReviewStatus::Approved) {
                    return $current;
                }

                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $this->audit(
                request: $updated,
                actorUserId: $actorUserId,
                action: 'approved',
                fromReviewStatus: WithdrawalReviewStatus::Pending,
                toReviewStatus: WithdrawalReviewStatus::Approved,
                fromPaymentStatus: WithdrawalPaymentStatus::NotStarted,
                toPaymentStatus: WithdrawalPaymentStatus::Pending,
                proofId: null,
                notes: $notes,
                metadata: null,
                now: $now,
            );

            return $updated;
        });
    }

    public function markPaid(
        int $withdrawalRequestId,
        int $actorUserId,
        int $proofId,
        ?string $notes,
        DateTimeImmutable $now,
    ): WithdrawalRequest {
        $this->validateTransitionIdentity($withdrawalRequestId, $actorUserId);
        if ($proofId <= 0) {
            throw new InvalidArgumentException('proof_id must be a positive integer.');
        }
        $notes = $this->normalizeNotes($notes, 'payment_notes', required: true);

        return $this->repository->transactional(function () use (
            $withdrawalRequestId,
            $actorUserId,
            $proofId,
            $notes,
            $now,
        ): WithdrawalRequest {
            $request = $this->repository->findRequestForUpdate($withdrawalRequestId);
            if ($request === null) {
                throw new RuntimeException('withdrawal_not_found');
            }

            if ($request->paymentStatus === WithdrawalPaymentStatus::Paid) {
                if ($request->paymentProofId === $proofId) {
                    return $request;
                }

                throw new RuntimeException('withdrawal_payment_proof_conflict');
            }

            if ($request->reviewStatus !== WithdrawalReviewStatus::Approved) {
                throw new RuntimeException('withdrawal_not_approved');
            }
            if ($request->paymentStatus !== WithdrawalPaymentStatus::Pending) {
                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $proof = $this->repository->findProofForUpdate($proofId, $withdrawalRequestId);
            if (
                $proof === null
                || $proof->withdrawalRequestId !== $withdrawalRequestId
                || $proof->organizationId !== $request->organizationId
                || $proof->status !== WithdrawalProofStatus::Verified
                || $proof->checksum === null
                || $proof->verifiedAt === null
            ) {
                throw new RuntimeException('withdrawal_verified_proof_required');
            }

            $updated = $this->repository->markPaidIfReady($withdrawalRequestId, $proofId, $actorUserId, $notes, $now);
            if ($updated === null) {
                $current = $this->repository->findRequest($withdrawalRequestId);
                if ($current?->paymentStatus === WithdrawalPaymentStatus::Paid && $current->paymentProofId === $proofId) {
                    return $current;
                }

                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $this->audit(
                request: $updated,
                actorUserId: $actorUserId,
                action: 'paid',
                fromReviewStatus: WithdrawalReviewStatus::Approved,
                toReviewStatus: WithdrawalReviewStatus::Approved,
                fromPaymentStatus: WithdrawalPaymentStatus::Pending,
                toPaymentStatus: WithdrawalPaymentStatus::Paid,
                proofId: $proofId,
                notes: $notes,
                metadata: ['proof_checksum' => $proof->checksum],
                now: $now,
            );

            return $updated;
        });
    }

    /** @return list<WithdrawalRequest> */
    public function listQueue(
        ?WithdrawalReviewStatus $reviewStatus,
        ?WithdrawalPaymentStatus $paymentStatus,
        ?int $publisherOrganizationId,
        int $limit,
    ): array {
        if ($publisherOrganizationId !== null && $publisherOrganizationId <= 0) {
            throw new InvalidArgumentException('publisher_organization_id must be a positive integer when provided.');
        }

        return $this->repository->listRequests($reviewStatus, $paymentStatus, $publisherOrganizationId, $limit);
    }

    public function reject(int $withdrawalRequestId, int $actorUserId, ?string $notes, DateTimeImmutable $now): WithdrawalRequest
    {
        return $this->terminalTransition(
            withdrawalRequestId: $withdrawalRequestId,
            actorUserId: $actorUserId,
            notes: $notes,
            now: $now,
            targetStatus: WithdrawalReviewStatus::Rejected,
            action: 'rejected',
            expectedOrganizationId: null,
        );
    }

    public function revoke(
        int $withdrawalRequestId,
        int $actorUserId,
        ?string $notes,
        DateTimeImmutable $now,
        ?int $organizationId = null,
    ): WithdrawalRequest {
        return $this->terminalTransition(
            withdrawalRequestId: $withdrawalRequestId,
            actorUserId: $actorUserId,
            notes: $notes,
            now: $now,
            targetStatus: WithdrawalReviewStatus::Revoked,
            action: 'revoked',
            expectedOrganizationId: $organizationId,
        );
    }

    /** @param array<string, mixed> $payoutAccount */
    public function resubmit(
        int $withdrawalRequestId,
        int $actorUserId,
        array $payoutAccount,
        ?string $notes,
        DateTimeImmutable $now,
        ?int $organizationId = null,
    ): WithdrawalRequest {
        $this->validateTransitionIdentity($withdrawalRequestId, $actorUserId);
        $payoutAccount = $this->normalizePayoutAccount($payoutAccount);
        $notes = $this->normalizeNotes($notes, 'applicant_notes');

        return $this->repository->transactional(function () use (
            $withdrawalRequestId,
            $actorUserId,
            $payoutAccount,
            $notes,
            $now,
            $organizationId,
        ): WithdrawalRequest {
            $request = $this->lockRequestWithOrganization($withdrawalRequestId, $organizationId);

            if (
                $request->reviewStatus === WithdrawalReviewStatus::Pending
                && $request->paymentStatus === WithdrawalPaymentStatus::NotStarted
                && $request->payoutAccount == $payoutAccount
                && $request->applicantNotes === $notes
                && $request->resubmittedAt !== null
            ) {
                return $request;
            }

            if (
                !in_array($request->reviewStatus, [WithdrawalReviewStatus::Rejected, WithdrawalReviewStatus::Revoked], true)
                || $request->paymentStatus !== WithdrawalPaymentStatus::NotStarted
            ) {
                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $ledgerEntry = $this->holdPublisherEarnings(
                organizationId: $request->organizationId,
                pointsAmount: $request->pointsAmount,
                idempotencyKey: $this->ledgerIdempotencyKey(
                    'withdrawal-resubmit',
                    $request->organizationId,
                    (string) $withdrawalRequestId,
                    (string) $request->ledgerEntryId,
                    $request->reviewStatus->value,
                ),
                referenceType: 'withdrawal_request',
                referenceId: $withdrawalRequestId,
                memo: 'Publisher withdrawal resubmitted hold',
                metadata: ['actor_user_id' => $actorUserId, 'from_review_status' => $request->reviewStatus->value],
            );

            $updated = $this->repository->resubmitIfTerminal(
                id: $withdrawalRequestId,
                expectedReviewStatus: $request->reviewStatus,
                payoutAccount: $payoutAccount,
                applicantNotes: $notes,
                ledgerEntryId: (int) $ledgerEntry->id,
                now: $now,
            );
            if ($updated === null) {
                $current = $this->repository->findRequest($withdrawalRequestId);
                if (
                    $current?->reviewStatus === WithdrawalReviewStatus::Pending
                    && $current->paymentStatus === WithdrawalPaymentStatus::NotStarted
                    && $current->payoutAccount == $payoutAccount
                    && $current->applicantNotes === $notes
                ) {
                    return $current;
                }

                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $this->audit(
                request: $updated,
                actorUserId: $actorUserId,
                action: 'resubmitted',
                fromReviewStatus: $request->reviewStatus,
                toReviewStatus: WithdrawalReviewStatus::Pending,
                fromPaymentStatus: WithdrawalPaymentStatus::NotStarted,
                toPaymentStatus: WithdrawalPaymentStatus::NotStarted,
                proofId: null,
                notes: $notes,
                metadata: [
                    'previous_ledger_entry_id' => $request->ledgerEntryId,
                    'ledger_entry_id' => $ledgerEntry->id,
                ],
                now: $now,
            );

            return $updated;
        });
    }

    private function terminalTransition(
        int $withdrawalRequestId,
        int $actorUserId,
        ?string $notes,
        DateTimeImmutable $now,
        WithdrawalReviewStatus $targetStatus,
        string $action,
        ?int $expectedOrganizationId,
    ): WithdrawalRequest {
        $this->validateTransitionIdentity($withdrawalRequestId, $actorUserId);
        $notes = $this->normalizeNotes(
            $notes,
            $targetStatus === WithdrawalReviewStatus::Rejected ? 'reviewer_notes' : 'applicant_notes',
            required: $targetStatus === WithdrawalReviewStatus::Rejected,
        );

        return $this->repository->transactional(function () use (
            $withdrawalRequestId,
            $actorUserId,
            $notes,
            $now,
            $targetStatus,
            $action,
            $expectedOrganizationId,
        ): WithdrawalRequest {
            $request = $this->lockRequestWithOrganization($withdrawalRequestId, $expectedOrganizationId);

            if ($request->reviewStatus === $targetStatus) {
                return $request;
            }

            if (
                $request->reviewStatus !== WithdrawalReviewStatus::Pending
                || $request->paymentStatus !== WithdrawalPaymentStatus::NotStarted
            ) {
                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $updated = $targetStatus === WithdrawalReviewStatus::Rejected
                ? $this->repository->rejectIfPending($withdrawalRequestId, $actorUserId, $notes, $now)
                : $this->repository->revokeIfPending($withdrawalRequestId, $now);
            if ($updated === null) {
                $current = $this->repository->findRequest($withdrawalRequestId);
                if ($current?->reviewStatus === $targetStatus) {
                    return $current;
                }

                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $this->restoreHeldPoints($updated, $actorUserId, $action);
            $this->audit(
                request: $updated,
                actorUserId: $actorUserId,
                action: $action,
                fromReviewStatus: WithdrawalReviewStatus::Pending,
                toReviewStatus: $targetStatus,
                fromPaymentStatus: WithdrawalPaymentStatus::NotStarted,
                toPaymentStatus: WithdrawalPaymentStatus::NotStarted,
                proofId: null,
                notes: $notes,
                metadata: null,
                now: $now,
            );

            return $updated;
        });
    }

    private function lockRequestWithOrganization(int $withdrawalRequestId, ?int $expectedOrganizationId): WithdrawalRequest
    {
        $snapshot = $this->repository->findRequest($withdrawalRequestId);
        if ($snapshot === null || ($expectedOrganizationId !== null && $snapshot->organizationId !== $expectedOrganizationId)) {
            throw new RuntimeException('withdrawal_not_found');
        }

        $this->repository->lockOrganizationForUpdate($snapshot->organizationId);
        $request = $this->repository->findRequestForUpdate($withdrawalRequestId);
        if (
            $request === null
            || $request->organizationId !== $snapshot->organizationId
            || ($expectedOrganizationId !== null && $request->organizationId !== $expectedOrganizationId)
        ) {
            throw new RuntimeException('withdrawal_not_found');
        }

        return $request;
    }

    /** @param array<string, mixed> $metadata */
    private function holdPublisherEarnings(
        int $organizationId,
        int $pointsAmount,
        string $idempotencyKey,
        ?string $referenceType,
        ?int $referenceId,
        string $memo,
        array $metadata,
    ): PointsLedgerEntry {
        $entry = $this->ledgerRepository->tryDebit(new PointsLedgerEntry(
            id: null,
            organizationId: $organizationId,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: $pointsAmount,
            direction: LedgerDirection::Debit,
            balanceAfterPoints: null,
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey,
            memo: $memo,
            metadata: $metadata,
        ));

        if ($entry === null) {
            throw new RuntimeException('insufficient_publisher_earnings');
        }

        return $entry;
    }

    private function validateRequest(int $organizationId, int $requestedByUserId, int $pointsAmount): void
    {
        if ($organizationId <= 0 || $requestedByUserId <= 0 || $pointsAmount <= 0) {
            throw new InvalidArgumentException('Withdrawal request is invalid.');
        }
    }

    private function validateTransitionIdentity(int $withdrawalRequestId, int $actorUserId): void
    {
        if ($withdrawalRequestId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('Withdrawal transition identity is invalid.');
        }
    }

    private function normalizePayoutMethod(string $payoutMethod): string
    {
        $payoutMethod = strtolower(trim($payoutMethod));
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $payoutMethod) !== 1) {
            throw new InvalidArgumentException('payout_method must be a stable lowercase provider code of at most 64 characters.');
        }

        return $payoutMethod;
    }

    /**
     * @param array<string, mixed> $payoutAccount
     * @return array<string, mixed>
     */
    private function normalizePayoutAccount(array $payoutAccount): array
    {
        if ($payoutAccount === [] || array_is_list($payoutAccount)) {
            throw new InvalidArgumentException('payout_account must be a non-empty object.');
        }
        foreach (array_keys($payoutAccount) as $key) {
            if (!is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('payout_account keys must be non-empty strings.');
            }
        }

        try {
            $json = json_encode($payoutAccount, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('payout_account must contain valid JSON values.', previous: $exception);
        }
        if (strlen($json) > self::PAYOUT_ACCOUNT_MAX_BYTES) {
            throw new InvalidArgumentException('payout_account must serialize to at most 8192 bytes.');
        }

        return $payoutAccount;
    }

    private function normalizeNotes(?string $notes, string $field, bool $required = false): ?string
    {
        $notes = $this->normalizeText($notes);
        if ($required && $notes === null) {
            throw new InvalidArgumentException($field . ' is required.');
        }
        if ($notes !== null && strlen($notes) > self::NOTES_MAX_LENGTH) {
            throw new InvalidArgumentException($field . ' must be at most 2000 characters.');
        }

        return $notes;
    }

    private function amountCny(int $pointsAmount): string
    {
        return sprintf('%d.%02d', intdiv($pointsAmount, self::POINTS_PER_CNY), $pointsAmount % self::POINTS_PER_CNY);
    }

    /** @param array<string, mixed> $payoutAccount */
    private function assertSameIdempotentRequest(
        WithdrawalRequest $existing,
        int $requestedByUserId,
        int $pointsAmount,
        string $payoutMethod,
        array $payoutAccount,
        ?string $notes,
    ): void {
        if (
            $existing->requestedByUserId !== $requestedByUserId
            || $existing->pointsAmount !== $pointsAmount
            || $existing->payoutMethod !== $payoutMethod
            || $existing->payoutAccount != $payoutAccount
            || $existing->applicantNotes !== $notes
        ) {
            throw new RuntimeException('withdrawal_idempotency_conflict');
        }
    }

    private function normalizeIdempotencyKey(string $idempotencyKey): string
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Withdrawal idempotency key is required.');
        }
        if (strlen($idempotencyKey) > self::IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw new InvalidArgumentException('Withdrawal idempotency key must be at most 160 characters.');
        }

        return $idempotencyKey;
    }

    private function ledgerIdempotencyKey(string $prefix, int $organizationId, string ...$parts): string
    {
        return $prefix . ':' . $organizationId . ':' . hash('sha256', implode("\n", $parts));
    }

    private function restoreHeldPoints(WithdrawalRequest $request, int $actorUserId, string $reason): void
    {
        $this->ledger->credit(
            organizationId: $request->organizationId,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: $request->pointsAmount,
            idempotencyKey: 'withdrawal-restore:' . $request->id . ':' . $request->ledgerEntryId . ':' . $reason,
            referenceType: 'withdrawal_request',
            referenceId: $request->id,
            memo: 'Publisher withdrawal hold restored: ' . $reason,
            metadata: ['actor_user_id' => $actorUserId, 'reason' => $reason],
        );
    }

    /** @param array<string, mixed>|null $metadata */
    private function audit(
        WithdrawalRequest $request,
        ?int $actorUserId,
        string $action,
        ?WithdrawalReviewStatus $fromReviewStatus,
        ?WithdrawalReviewStatus $toReviewStatus,
        ?WithdrawalPaymentStatus $fromPaymentStatus,
        ?WithdrawalPaymentStatus $toPaymentStatus,
        ?int $proofId,
        ?string $notes,
        ?array $metadata,
        DateTimeImmutable $now,
    ): void {
        $this->repository->appendAuditEvent(
            withdrawalRequestId: $request->id ?? 0,
            organizationId: $request->organizationId,
            actorUserId: $actorUserId,
            action: $action,
            fromReviewStatus: $fromReviewStatus,
            toReviewStatus: $toReviewStatus,
            fromPaymentStatus: $fromPaymentStatus,
            toPaymentStatus: $toPaymentStatus,
            proofId: $proofId,
            notes: $this->normalizeText($notes),
            metadata: $metadata,
            now: $now,
        );
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
