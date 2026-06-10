<?php

declare(strict_types=1);

namespace VertoAD\Service\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Billing\WithdrawalRequest;
use VertoAD\Domain\Billing\WithdrawalStatus;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\PointsLedgerRepositoryInterface;
use VertoAD\Service\PointsLedgerService;

final class WithdrawalService
{
    public function __construct(
        private readonly WithdrawalRepository $repository,
        private readonly PointsLedgerService $ledger,
        private readonly PointsLedgerRepositoryInterface $ledgerRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $payoutAccount
     */
    public function requestWithdrawal(
        int $organizationId,
        int $requestedByUserId,
        int $pointsAmount,
        string $payoutMethod,
        array $payoutAccount,
        ?string $notes,
        DateTimeImmutable $now,
    ): WithdrawalRequest {
        $this->validateRequest($organizationId, $requestedByUserId, $pointsAmount, $payoutMethod);

        return $this->repository->transactional(function () use (
            $organizationId,
            $requestedByUserId,
            $pointsAmount,
            $payoutMethod,
            $payoutAccount,
            $notes,
            $now,
        ): WithdrawalRequest {
            $this->repository->lockOrganizationForUpdate($organizationId);
            if ($this->ledgerRepository->balanceForOrganization($organizationId, 'publisher_earnings') < $pointsAmount) {
                throw new RuntimeException('insufficient_publisher_earnings');
            }

            $ledgerEntry = $this->ledger->debit(
                organizationId: $organizationId,
                accountType: 'publisher_earnings',
                accountId: null,
                pointsAmount: $pointsAmount,
                idempotencyKey: 'withdrawal-request:' . $organizationId . ':' . $requestedByUserId . ':' . $now->format('U.u'),
                referenceType: 'withdrawal_request',
                referenceId: null,
                memo: 'Publisher withdrawal hold',
                metadata: ['requested_by_user_id' => $requestedByUserId],
            );

            $request = $this->repository->createRequest(
                organizationId: $organizationId,
                requestedByUserId: $requestedByUserId,
                pointsAmount: $pointsAmount,
                payoutMethod: trim($payoutMethod),
                payoutAccount: $payoutAccount,
                notes: $this->normalizeText($notes),
                ledgerEntryId: $ledgerEntry->id,
                now: $now,
            );
            $this->audit($request, $requestedByUserId, 'requested', null, WithdrawalStatus::Requested, $notes, null, $now);

            return $request;
        });
    }

    public function markPaid(int $withdrawalRequestId, int $actorUserId, ?string $notes, DateTimeImmutable $now): WithdrawalRequest
    {
        return $this->transitionFromRequested(
            withdrawalRequestId: $withdrawalRequestId,
            actorUserId: $actorUserId,
            notes: $notes,
            now: $now,
            targetStatus: WithdrawalStatus::Paid,
            action: 'paid',
            reviewerUserId: $actorUserId,
            restoreHeldPoints: false,
        );
    }

    public function reject(int $withdrawalRequestId, int $actorUserId, ?string $notes, DateTimeImmutable $now): WithdrawalRequest
    {
        return $this->transitionFromRequested(
            withdrawalRequestId: $withdrawalRequestId,
            actorUserId: $actorUserId,
            notes: $notes,
            now: $now,
            targetStatus: WithdrawalStatus::Rejected,
            action: 'rejected',
            reviewerUserId: $actorUserId,
            restoreHeldPoints: true,
        );
    }

    public function revoke(int $withdrawalRequestId, int $actorUserId, ?string $notes, DateTimeImmutable $now): WithdrawalRequest
    {
        return $this->transitionFromRequested(
            withdrawalRequestId: $withdrawalRequestId,
            actorUserId: $actorUserId,
            notes: $notes,
            now: $now,
            targetStatus: WithdrawalStatus::Revoked,
            action: 'revoked',
            reviewerUserId: null,
            restoreHeldPoints: true,
        );
    }

    /**
     * @param array<string, mixed> $payoutAccount
     */
    public function resubmit(
        int $withdrawalRequestId,
        int $actorUserId,
        array $payoutAccount,
        ?string $notes,
        DateTimeImmutable $now,
    ): WithdrawalRequest {
        $request = $this->repository->findRequest($withdrawalRequestId);
        if ($request === null || $request->status !== WithdrawalStatus::Revoked) {
            throw new RuntimeException('withdrawal_transition_not_allowed');
        }
        if ($this->ledgerRepository->balanceForOrganization($request->organizationId, 'publisher_earnings') < $request->pointsAmount) {
            throw new RuntimeException('insufficient_publisher_earnings');
        }

        $this->ledger->debit(
            organizationId: $request->organizationId,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: $request->pointsAmount,
            idempotencyKey: 'withdrawal-resubmit:' . $withdrawalRequestId . ':' . $now->format('U.u'),
            referenceType: 'withdrawal_request',
            referenceId: $withdrawalRequestId,
            memo: 'Publisher withdrawal resubmitted hold',
            metadata: ['actor_user_id' => $actorUserId],
        );

        $updated = $this->repository->updateRequestState(
            id: $withdrawalRequestId,
            status: WithdrawalStatus::Requested,
            reviewerUserId: null,
            reviewerNotes: $this->normalizeText($notes),
            payoutAccount: $payoutAccount,
            now: $now,
        );
        $this->audit($updated, $actorUserId, 'resubmitted', $request->status, WithdrawalStatus::Requested, $notes, null, $now);

        return $updated;
    }

    private function transitionFromRequested(
        int $withdrawalRequestId,
        int $actorUserId,
        ?string $notes,
        DateTimeImmutable $now,
        WithdrawalStatus $targetStatus,
        string $action,
        ?int $reviewerUserId,
        bool $restoreHeldPoints,
    ): WithdrawalRequest {
        return $this->repository->transactional(function () use (
            $withdrawalRequestId,
            $actorUserId,
            $notes,
            $now,
            $targetStatus,
            $action,
            $reviewerUserId,
            $restoreHeldPoints,
        ): WithdrawalRequest {
            $request = $this->repository->findRequest($withdrawalRequestId);
            if ($request === null) {
                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            if ($request->status !== WithdrawalStatus::Requested) {
                if ($request->status === $targetStatus) {
                    return $request;
                }

                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            $updated = $this->repository->updateRequestStateIfCurrent(
                id: $withdrawalRequestId,
                expectedStatus: WithdrawalStatus::Requested,
                status: $targetStatus,
                reviewerUserId: $reviewerUserId,
                reviewerNotes: $this->normalizeText($notes),
                payoutAccount: null,
                now: $now,
            );

            if ($updated === null) {
                $current = $this->repository->findRequest($withdrawalRequestId);
                if ($current !== null && $current->status === $targetStatus) {
                    return $current;
                }

                throw new RuntimeException('withdrawal_transition_not_allowed');
            }

            if ($restoreHeldPoints) {
                $this->restoreHeldPoints($updated, $actorUserId, $action);
            }
            $this->audit($updated, $actorUserId, $action, WithdrawalStatus::Requested, $targetStatus, $notes, null, $now);

            return $updated;
        });
    }

    private function validateRequest(int $organizationId, int $requestedByUserId, int $pointsAmount, string $payoutMethod): void
    {
        if ($organizationId <= 0 || $requestedByUserId <= 0 || $pointsAmount <= 0 || trim($payoutMethod) === '') {
            throw new InvalidArgumentException('Withdrawal request is invalid.');
        }
    }

    private function restoreHeldPoints(WithdrawalRequest $request, int $actorUserId, string $reason): void
    {
        $this->ledger->credit(
            organizationId: $request->organizationId,
            accountType: 'publisher_earnings',
            accountId: null,
            pointsAmount: $request->pointsAmount,
            idempotencyKey: 'withdrawal-restore:' . $request->id . ':' . $reason,
            referenceType: 'withdrawal_request',
            referenceId: $request->id,
            memo: 'Publisher withdrawal hold restored: ' . $reason,
            metadata: ['actor_user_id' => $actorUserId, 'reason' => $reason],
        );
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function audit(
        WithdrawalRequest $request,
        ?int $actorUserId,
        string $action,
        ?WithdrawalStatus $fromStatus,
        WithdrawalStatus $toStatus,
        ?string $notes,
        ?array $metadata,
        DateTimeImmutable $now,
    ): void {
        $this->repository->appendAuditEvent(
            withdrawalRequestId: $request->id ?? 0,
            organizationId: $request->organizationId,
            actorUserId: $actorUserId,
            action: $action,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
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
