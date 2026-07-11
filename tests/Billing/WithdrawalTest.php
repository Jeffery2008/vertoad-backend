<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Billing\WithdrawalPaymentStatus;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalReviewStatus;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\PointsLedgerService;

final class WithdrawalTest extends TestCase
{
    public function testApprovalAndVerifiedProofAreIndependentPaymentPreconditions(): void
    {
        [$connection, $repository, $service, $ledger] = $this->fixture(42, 5_000);
        $requested = $service->requestWithdrawal(
            organizationId: 42,
            requestedByUserId: 7,
            pointsAmount: 2_500,
            payoutMethod: 'bank_transfer',
            payoutAccount: ['account_no' => '****1234'],
            notes: 'June earnings',
            idempotencyKey: 'withdrawal:production:lifecycle',
            now: $this->at('12:00'),
        );

        self::assertSame(WithdrawalReviewStatus::Pending, $requested->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::NotStarted, $requested->paymentStatus);
        self::assertSame('25.00', $requested->amountCny);
        self::assertSame(2_500, $ledger->balanceForOrganization(42, 'publisher_earnings'));

        $notApproved = $this->captureRuntime(
            fn () => $service->markPaid((int) $requested->id, 99, 1, 'paid', $this->at('12:05')),
        );
        self::assertSame('withdrawal_not_approved', $notApproved->getMessage());

        $approved = $service->approve((int) $requested->id, 98, 'account verified', $this->at('12:10'));
        self::assertSame(WithdrawalReviewStatus::Approved, $approved->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::Pending, $approved->paymentStatus);
        self::assertSame(98, $approved->reviewerUserId);
        self::assertNotNull($approved->approvedAt);
        self::assertNull($approved->paidAt);

        $missingProof = $this->captureRuntime(
            fn () => $service->markPaid((int) $requested->id, 99, 999, 'paid', $this->at('12:15')),
        );
        self::assertSame('withdrawal_verified_proof_required', $missingProof->getMessage());

        $pendingProof = $repository->createProof(
            (int) $requested->id,
            42,
            99,
            'withdrawals/42/' . $requested->id . '/proof-pending.pdf',
            'application/pdf',
            1_024,
            $this->at('12:20'),
        );
        $pendingRejected = $this->captureRuntime(
            fn () => $service->markPaid((int) $requested->id, 99, (int) $pendingProof->id, 'paid', $this->at('12:21')),
        );
        self::assertSame('withdrawal_verified_proof_required', $pendingRejected->getMessage());

        $proof = $this->verifiedProof($repository, $requested->id ?? 0, 42, 99, 'valid', $this->at('12:25'));
        $paid = $service->markPaid((int) $requested->id, 99, (int) $proof->id, 'bank transfer completed', $this->at('12:30'));

        self::assertSame(WithdrawalReviewStatus::Approved, $paid->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::Paid, $paid->paymentStatus);
        self::assertSame($proof->id, $paid->paymentProofId);
        self::assertSame(99, $paid->paymentCompletedByUserId);
        self::assertSame('bank transfer completed', $paid->paymentNotes);
        self::assertSame(98, $paid->reviewerUserId);
        self::assertNotNull($paid->paidAt);

        $paidReplay = $service->markPaid((int) $requested->id, 99, (int) $proof->id, 'ignored replay note', $this->at('12:35'));
        self::assertEquals($paid->paidAt, $paidReplay->paidAt);
        self::assertSame('bank transfer completed', $paidReplay->paymentNotes);

        $differentProof = $this->captureRuntime(
            fn () => $service->markPaid((int) $requested->id, 99, 123_456, 'conflict', $this->at('12:40')),
        );
        self::assertSame('withdrawal_payment_proof_conflict', $differentProof->getMessage());
        self::assertSame(
            ['requested', 'approved', 'paid'],
            array_column($connection->fetchAllAssociative('SELECT action FROM withdrawal_audit_events ORDER BY id'), 'action'),
        );
    }

    public function testRejectRevokeAndResubmitRestoreAndReholdExactlyOnceAcrossCycles(): void
    {
        [$connection, , $service, $ledger] = $this->fixture(42, 2_000);
        $request = $service->requestWithdrawal(42, 7, 500, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:cycles', $this->at('12:00'));
        self::assertSame(1_500, $ledger->balanceForOrganization(42, 'publisher_earnings'));

        $rejected = $service->reject((int) $request->id, 99, 'bad account', $this->at('12:05'));
        $rejectedReplay = $service->reject((int) $request->id, 99, 'bad account', $this->at('12:06'));
        self::assertSame(WithdrawalReviewStatus::Rejected, $rejected->reviewStatus);
        self::assertEquals($rejected->rejectedAt, $rejectedReplay->rejectedAt);
        self::assertSame(2_000, $ledger->balanceForOrganization(42, 'publisher_earnings'));

        $resubmitted = $service->resubmit(
            (int) $request->id,
            7,
            ['account_no' => 'y'],
            'fixed',
            $this->at('12:10'),
            organizationId: 42,
        );
        $resubmittedReplay = $service->resubmit(
            (int) $request->id,
            7,
            ['account_no' => 'y'],
            'fixed',
            $this->at('12:11'),
            organizationId: 42,
        );
        self::assertSame(WithdrawalReviewStatus::Pending, $resubmitted->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::NotStarted, $resubmitted->paymentStatus);
        self::assertSame($resubmitted->ledgerEntryId, $resubmittedReplay->ledgerEntryId);
        self::assertSame(['account_no' => 'y'], $resubmitted->payoutAccount);
        self::assertSame('fixed', $resubmitted->applicantNotes);
        self::assertSame(1_500, $ledger->balanceForOrganization(42, 'publisher_earnings'));

        $revoked = $service->revoke((int) $request->id, 7, 'cancelled', $this->at('12:15'), organizationId: 42);
        $revokedReplay = $service->revoke((int) $request->id, 7, 'cancelled', $this->at('12:16'), organizationId: 42);
        self::assertSame(WithdrawalReviewStatus::Revoked, $revoked->reviewStatus);
        self::assertEquals($revoked->revokedAt, $revokedReplay->revokedAt);
        self::assertSame(2_000, $ledger->balanceForOrganization(42, 'publisher_earnings'));

        $secondResubmit = $service->resubmit((int) $request->id, 7, ['account_no' => 'z'], null, $this->at('12:20'));
        self::assertSame(WithdrawalReviewStatus::Pending, $secondResubmit->reviewStatus);
        self::assertSame(1_500, $ledger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(
            ['requested', 'rejected', 'resubmitted', 'revoked', 'resubmitted'],
            array_column($connection->fetchAllAssociative('SELECT action FROM withdrawal_audit_events ORDER BY id'), 'action'),
        );
        self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
    }

    public function testApprovedWithdrawalCannotBeRejectedRevokedOrResubmitted(): void
    {
        [, , $service] = $this->fixture(42, 1_000);
        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:approved-terminal', $this->at('12:00'));
        $service->approve((int) $request->id, 99, null, $this->at('12:05'));

        foreach ([
            fn () => $service->reject((int) $request->id, 99, 'reason', $this->at('12:06')),
            fn () => $service->revoke((int) $request->id, 7, null, $this->at('12:07')),
            fn () => $service->resubmit((int) $request->id, 7, ['account_no' => 'y'], null, $this->at('12:08')),
        ] as $operation) {
            self::assertSame('withdrawal_transition_not_allowed', $this->captureRuntime($operation)->getMessage());
        }
    }

    public function testApprovalRejectsMissingAndTerminalRequests(): void
    {
        [$connection, , $service] = $this->fixture(42, 1_000);

        self::assertSame(
            'withdrawal_not_found',
            $this->captureRuntime(fn () => $service->approve(999, 99, null, $this->at('12:00')))->getMessage(),
        );

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:approve:terminal', $this->at('12:01'));
        $service->reject((int) $request->id, 99, 'invalid account', $this->at('12:02'));

        self::assertSame(
            'withdrawal_transition_not_allowed',
            $this->captureRuntime(fn () => $service->approve((int) $request->id, 100, null, $this->at('12:03')))->getMessage(),
        );
        self::assertSame('rejected', (string) $connection->fetchOne('SELECT review_status FROM withdrawal_requests WHERE id = ?', [$request->id]));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'approved'"));
    }

    public function testRequiredReviewerNotesAreValidatedBeforeRejection(): void
    {
        [$connection, , $service, $ledger] = $this->fixture(42, 1_000);
        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:reject:notes', $this->at('12:00'));

        $exception = $this->captureInvalidArgument(
            fn () => $service->reject((int) $request->id, 99, '   ', $this->at('12:01')),
        );

        self::assertSame('reviewer_notes is required.', $exception->getMessage());
        self::assertSame(600, $ledger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame('pending', (string) $connection->fetchOne('SELECT review_status FROM withdrawal_requests WHERE id = ?', [$request->id]));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'rejected'"));
    }

    public function testRequestAndApprovalAreIdempotentWithoutDuplicateFinancialOrAuditEffects(): void
    {
        [$connection, , $service, $ledger] = $this->fixture(42, 1_000);
        $first = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], 'note', 'withdrawal:idempotent', $this->at('12:00'));
        $second = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], 'note', ' withdrawal:idempotent ', $this->at('12:01'));
        self::assertSame($first->id, $second->id);
        self::assertSame(600, $ledger->balanceForOrganization(42, 'publisher_earnings'));

        $approved = $service->approve((int) $first->id, 99, 'ok', $this->at('12:02'));
        $approvedReplay = $service->approve((int) $first->id, 100, 'different replay', $this->at('12:03'));
        self::assertEquals($approved->approvedAt, $approvedReplay->approvedAt);
        self::assertSame(99, $approvedReplay->reviewerUserId);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'requested'"));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'approved'"));

        $conflict = $this->captureRuntime(
            fn () => $service->requestWithdrawal(42, 7, 401, 'bank_transfer', ['account_no' => 'x'], 'note', 'withdrawal:idempotent', $this->at('12:04')),
        );
        self::assertSame('withdrawal_idempotency_conflict', $conflict->getMessage());
    }

    public function testApprovalCompareAndSwapHandlesConcurrentWinnerAndMiss(): void
    {
        [$winnerConnection, , $winnerService, $winnerLedger] = $this->fixture(42, 1_000);
        $winnerRequest = $winnerService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:approve:race:winner', $this->at('12:00'));
        $this->installWithdrawalCompareAndSwapRace(
            $winnerConnection,
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            WithdrawalReviewStatus::Approved,
            WithdrawalPaymentStatus::Pending,
            concurrentWinner: true,
        );

        $approved = $winnerService->approve((int) $winnerRequest->id, 99, 'approved concurrently', $this->at('12:01'));

        self::assertSame(WithdrawalReviewStatus::Approved, $approved->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::Pending, $approved->paymentStatus);
        self::assertSame(99, $approved->reviewerUserId);
        self::assertSame(600, $winnerLedger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $winnerConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'approved'"));

        [$missConnection, , $missService, $missLedger] = $this->fixture(42, 1_000);
        $missRequest = $missService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:approve:race:miss', $this->at('12:02'));
        $this->installWithdrawalCompareAndSwapRace(
            $missConnection,
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            WithdrawalReviewStatus::Approved,
            WithdrawalPaymentStatus::Pending,
            concurrentWinner: false,
        );

        $exception = $this->captureRuntime(
            fn () => $missService->approve((int) $missRequest->id, 99, null, $this->at('12:03')),
        );

        self::assertSame('withdrawal_transition_not_allowed', $exception->getMessage());
        self::assertSame(WithdrawalReviewStatus::Pending, $missService->listQueue(null, null, 42, 10)[0]->reviewStatus);
        self::assertSame(600, $missLedger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $missConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'approved'"));
    }

    public function testMarkPaidCompareAndSwapHandlesConcurrentWinnerAndMiss(): void
    {
        [$winnerConnection, $winnerRepository, $winnerService] = $this->fixture(42, 1_000);
        $winnerRequest = $winnerService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:paid:race:winner', $this->at('12:00'));
        $winnerService->approve((int) $winnerRequest->id, 98, null, $this->at('12:01'));
        $winnerProof = $this->verifiedProof($winnerRepository, (int) $winnerRequest->id, 42, 99, 'paid-race-winner', $this->at('12:02'));
        $this->installWithdrawalCompareAndSwapRace(
            $winnerConnection,
            WithdrawalReviewStatus::Approved,
            WithdrawalPaymentStatus::Pending,
            WithdrawalReviewStatus::Approved,
            WithdrawalPaymentStatus::Paid,
            concurrentWinner: true,
        );

        $paid = $winnerService->markPaid((int) $winnerRequest->id, 99, (int) $winnerProof->id, 'paid concurrently', $this->at('12:03'));

        self::assertSame(WithdrawalPaymentStatus::Paid, $paid->paymentStatus);
        self::assertSame($winnerProof->id, $paid->paymentProofId);
        self::assertSame(99, $paid->paymentCompletedByUserId);
        self::assertSame(0, (int) $winnerConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'paid'"));

        [$missConnection, $missRepository, $missService] = $this->fixture(42, 1_000);
        $missRequest = $missService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:paid:race:miss', $this->at('12:04'));
        $missService->approve((int) $missRequest->id, 98, null, $this->at('12:05'));
        $missProof = $this->verifiedProof($missRepository, (int) $missRequest->id, 42, 99, 'paid-race-miss', $this->at('12:06'));
        $this->installWithdrawalCompareAndSwapRace(
            $missConnection,
            WithdrawalReviewStatus::Approved,
            WithdrawalPaymentStatus::Pending,
            WithdrawalReviewStatus::Approved,
            WithdrawalPaymentStatus::Paid,
            concurrentWinner: false,
        );

        $exception = $this->captureRuntime(
            fn () => $missService->markPaid((int) $missRequest->id, 99, (int) $missProof->id, 'paid', $this->at('12:07')),
        );

        self::assertSame('withdrawal_transition_not_allowed', $exception->getMessage());
        self::assertSame(WithdrawalPaymentStatus::Pending, $missRepository->findRequest((int) $missRequest->id)?->paymentStatus);
        self::assertSame(0, (int) $missConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'paid'"));
    }

    public function testResubmitCompareAndSwapHandlesConcurrentWinnerAndRollsBackMiss(): void
    {
        [$winnerConnection, , $winnerService, $winnerLedger] = $this->fixture(42, 1_000);
        $winnerRequest = $winnerService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'old'], null, 'withdrawal:resubmit:race:winner', $this->at('12:00'));
        $winnerService->revoke((int) $winnerRequest->id, 7, null, $this->at('12:01'));
        $this->installWithdrawalCompareAndSwapRace(
            $winnerConnection,
            WithdrawalReviewStatus::Revoked,
            WithdrawalPaymentStatus::NotStarted,
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            concurrentWinner: true,
        );

        $resubmitted = $winnerService->resubmit((int) $winnerRequest->id, 7, ['account_no' => 'new'], 'fixed', $this->at('12:02'));

        self::assertSame(WithdrawalReviewStatus::Pending, $resubmitted->reviewStatus);
        self::assertSame(WithdrawalPaymentStatus::NotStarted, $resubmitted->paymentStatus);
        self::assertSame(['account_no' => 'new'], $resubmitted->payoutAccount);
        self::assertSame('fixed', $resubmitted->applicantNotes);
        self::assertSame(600, $winnerLedger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $winnerConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'resubmitted'"));

        [$missConnection, $missRepository, $missService, $missLedger] = $this->fixture(42, 1_000);
        $missRequest = $missService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'old'], null, 'withdrawal:resubmit:race:miss', $this->at('12:03'));
        $missService->revoke((int) $missRequest->id, 7, null, $this->at('12:04'));
        $this->installWithdrawalCompareAndSwapRace(
            $missConnection,
            WithdrawalReviewStatus::Revoked,
            WithdrawalPaymentStatus::NotStarted,
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            concurrentWinner: false,
        );

        $exception = $this->captureRuntime(
            fn () => $missService->resubmit((int) $missRequest->id, 7, ['account_no' => 'new'], null, $this->at('12:05')),
        );

        self::assertSame('withdrawal_transition_not_allowed', $exception->getMessage());
        self::assertSame(WithdrawalReviewStatus::Revoked, $missRepository->findRequest((int) $missRequest->id)?->reviewStatus);
        self::assertSame(1_000, $missLedger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(3, (int) $missConnection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
        self::assertSame(0, (int) $missConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'resubmitted'"));
    }

    public function testTerminalCompareAndSwapHandlesConcurrentWinnerAndMiss(): void
    {
        [$winnerConnection, , $winnerService] = $this->fixture(42, 1_000);
        $winnerRequest = $winnerService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:revoke:race:winner', $this->at('12:00'));
        $this->installWithdrawalCompareAndSwapRace(
            $winnerConnection,
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            WithdrawalReviewStatus::Revoked,
            WithdrawalPaymentStatus::NotStarted,
            concurrentWinner: true,
        );

        $revoked = $winnerService->revoke((int) $winnerRequest->id, 7, null, $this->at('12:01'));

        self::assertSame(WithdrawalReviewStatus::Revoked, $revoked->reviewStatus);
        self::assertSame(0, (int) $winnerConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'revoked'"));

        [$missConnection, $missRepository, $missService, $missLedger] = $this->fixture(42, 1_000);
        $missRequest = $missService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:revoke:race:miss', $this->at('12:02'));
        $this->installWithdrawalCompareAndSwapRace(
            $missConnection,
            WithdrawalReviewStatus::Pending,
            WithdrawalPaymentStatus::NotStarted,
            WithdrawalReviewStatus::Revoked,
            WithdrawalPaymentStatus::NotStarted,
            concurrentWinner: false,
        );

        $exception = $this->captureRuntime(
            fn () => $missService->revoke((int) $missRequest->id, 7, null, $this->at('12:03')),
        );

        self::assertSame('withdrawal_transition_not_allowed', $exception->getMessage());
        self::assertSame(WithdrawalReviewStatus::Pending, $missRepository->findRequest((int) $missRequest->id)?->reviewStatus);
        self::assertSame(600, $missLedger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $missConnection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'revoked'"));
    }

    public function testQueueFiltersReviewAndPaymentStatusesIndependently(): void
    {
        [$connection, $repository, $service] = $this->fixture(42, 5_000);
        $ledger = new PointsLedgerService(new PointsLedgerRepository($connection));
        $ledger->credit(43, 'publisher_earnings', null, 5_000, 'withdrawal:queue:43');

        $pending = $service->requestWithdrawal(42, 7, 500, 'bank_transfer', ['account_no' => 'a'], null, 'withdrawal:queue:pending', $this->at('10:00'));
        $approved = $service->requestWithdrawal(42, 7, 600, 'bank_transfer', ['account_no' => 'b'], null, 'withdrawal:queue:approved', $this->at('11:00'));
        $paid = $service->requestWithdrawal(43, 8, 700, 'bank_transfer', ['account_no' => 'c'], null, 'withdrawal:queue:paid', $this->at('12:00'));
        $service->approve((int) $approved->id, 99, null, $this->at('11:05'));
        $service->approve((int) $paid->id, 99, null, $this->at('12:05'));
        $proof = $this->verifiedProof($repository, (int) $paid->id, 43, 99, 'queue', $this->at('12:06'));
        $service->markPaid((int) $paid->id, 99, (int) $proof->id, 'paid', $this->at('12:07'));

        self::assertSame(
            [$pending->id],
            array_column($service->listQueue(WithdrawalReviewStatus::Pending, null, null, 10), 'id'),
        );
        self::assertSame(
            [$paid->id],
            array_column($service->listQueue(null, WithdrawalPaymentStatus::Paid, null, 10), 'id'),
        );
        self::assertSame(
            [$approved->id],
            array_column($service->listQueue(WithdrawalReviewStatus::Approved, WithdrawalPaymentStatus::Pending, 42, 10), 'id'),
        );
        self::assertSame(
            [$approved->id, $pending->id],
            array_column($repository->listRequests(null, null, 42, 200), 'id'),
        );

        $invalid = $this->captureInvalidArgument(fn () => $service->listQueue(null, null, 0, 10));
        self::assertSame('publisher_organization_id must be a positive integer when provided.', $invalid->getMessage());
    }

    public function testPaymentProofMustBelongToTheSameWithdrawalAndOrganization(): void
    {
        [, $repository, $service] = $this->fixture(42, 2_000);
        $first = $service->requestWithdrawal(42, 7, 500, 'bank_transfer', ['account_no' => 'a'], null, 'withdrawal:proof-scope:a', $this->at('12:00'));
        $second = $service->requestWithdrawal(42, 7, 500, 'bank_transfer', ['account_no' => 'b'], null, 'withdrawal:proof-scope:b', $this->at('12:01'));
        $service->approve((int) $first->id, 99, null, $this->at('12:02'));
        $service->approve((int) $second->id, 99, null, $this->at('12:03'));
        $proof = $this->verifiedProof($repository, (int) $first->id, 42, 99, 'scope', $this->at('12:04'));

        $crossRequest = $this->captureRuntime(
            fn () => $service->markPaid((int) $second->id, 99, (int) $proof->id, 'paid', $this->at('12:05')),
        );
        self::assertSame('withdrawal_verified_proof_required', $crossRequest->getMessage());
        self::assertSame(WithdrawalPaymentStatus::Pending, $repository->findRequest((int) $second->id)?->paymentStatus);
    }

    public function testOwnTransitionsHideCrossOrganizationAndMissingRequests(): void
    {
        [, , $service] = $this->fixture(42, 1_000);
        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:scope', $this->at('12:00'));

        self::assertSame(
            'withdrawal_not_found',
            $this->captureRuntime(fn () => $service->revoke((int) $request->id, 8, null, $this->at('12:01'), organizationId: 43))->getMessage(),
        );
        self::assertSame(
            'withdrawal_not_found',
            $this->captureRuntime(fn () => $service->resubmit(999, 8, ['account_no' => 'x'], null, $this->at('12:02'), organizationId: 43))->getMessage(),
        );
    }

    public function testInvalidInputsAndInsufficientBalanceFailWithoutPartialRows(): void
    {
        [$connection, $repository, $service, $ledger] = $this->fixture(42, 100);
        self::assertNull($repository->findRequest(0));
        self::assertNull($repository->findRequestForUpdate(0));
        self::assertNull($repository->findProof(0));
        self::assertNull($repository->findProofForRequest(0, 1));
        self::assertSame([], $repository->listProofsForRequest(0, 10));
        self::assertNull($repository->findProofForUpdate(0, 1));
        self::assertNull($repository->findRequestByIdempotencyKey(0, 'x'));
        self::assertNull($repository->findRequestByIdempotencyKey(42, '   '));

        foreach ([
            [fn () => $service->requestWithdrawal(0, 7, 1, 'bank_transfer', ['account_no' => 'x'], null, 'x', $this->at('12:00')), 'Withdrawal request is invalid.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank transfer', ['account_no' => 'x'], null, 'x', $this->at('12:00')), 'payout_method must be a stable lowercase provider code of at most 64 characters.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', [], null, 'x', $this->at('12:00')), 'payout_account must be a non-empty object.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', ['' => 'x'], null, 'x', $this->at('12:00')), 'payout_account keys must be non-empty strings.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', ['value' => NAN], null, 'x', $this->at('12:00')), 'payout_account must contain valid JSON values.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', ['value' => str_repeat('x', 8_192)], null, 'x', $this->at('12:00')), 'payout_account must serialize to at most 8192 bytes.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', ['account_no' => 'x'], str_repeat('x', 2_001), 'x', $this->at('12:00')), 'applicant_notes must be at most 2000 characters.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', ['account_no' => 'x'], null, '   ', $this->at('12:00')), 'Withdrawal idempotency key is required.'],
            [fn () => $service->requestWithdrawal(42, 7, 1, 'bank_transfer', ['account_no' => 'x'], null, str_repeat('x', 161), $this->at('12:00')), 'Withdrawal idempotency key must be at most 160 characters.'],
            [fn () => $service->approve(1, 0, null, $this->at('12:00')), 'Withdrawal transition identity is invalid.'],
            [fn () => $service->markPaid(1, 99, 0, null, $this->at('12:00')), 'proof_id must be a positive integer.'],
        ] as [$operation, $message]) {
            self::assertSame($message, $this->captureInvalidArgument($operation)->getMessage());
        }

        $insufficient = $this->captureRuntime(
            fn () => $service->requestWithdrawal(42, 7, 101, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:insufficient', $this->at('12:01')),
        );
        self::assertSame('insufficient_publisher_earnings', $insufficient->getMessage());
        self::assertSame(100, $ledger->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM withdrawal_requests'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM withdrawal_audit_events'));
    }

    public function testMarkPaidRejectsDefensivelyCorruptedApprovedState(): void
    {
        [$sourceConnection, , $sourceService, $ledgerRepository] = $this->fixture(42, 1_000);
        $request = $sourceService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:corrupt-payment-state', $this->at('12:00'));
        $sourceService->approve((int) $request->id, 99, null, $this->at('12:01'));
        $row = $sourceConnection->fetchAssociative('SELECT * FROM withdrawal_requests WHERE id = ?', [$request->id]);
        self::assertIsArray($row);
        $row['payment_status'] = WithdrawalPaymentStatus::NotStarted->value;

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $operation): mixed => $operation());
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with('SELECT * FROM withdrawal_requests WHERE id = ?', [(int) $request->id])
            ->willReturn($row);
        $service = new WithdrawalService(
            new WithdrawalRepository($connection),
            new PointsLedgerService($ledgerRepository),
            $ledgerRepository,
        );

        $exception = $this->captureRuntime(
            fn () => $service->markPaid((int) $request->id, 100, 1, 'paid', $this->at('12:02')),
        );

        self::assertSame('withdrawal_transition_not_allowed', $exception->getMessage());
    }

    public function testOwnTransitionRejectsRequestThatDisappearsBeforeTheLockedRead(): void
    {
        [$sourceConnection, , $sourceService, $ledgerRepository] = $this->fixture(42, 1_000);
        $request = $sourceService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:locked-read:missing', $this->at('12:00'));
        $row = $sourceConnection->fetchAssociative('SELECT * FROM withdrawal_requests WHERE id = ?', [$request->id]);
        self::assertIsArray($row);

        $query = $this->createMock(QueryBuilder::class);
        $query->method('select')->willReturnSelf();
        $query->method('from')->willReturnSelf();
        $query->method('where')->willReturnSelf();
        $query->method('setParameter')->willReturnSelf();
        $query->expects(self::once())->method('fetchAssociative')->willReturn($row);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $operation): mixed => $operation());
        $connection->expects(self::once())->method('createQueryBuilder')->willReturn($query);
        $connection->expects(self::exactly(2))->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with('SELECT * FROM withdrawal_requests WHERE id = ?', [(int) $request->id])
            ->willReturn(false);
        $service = new WithdrawalService(
            new WithdrawalRepository($connection),
            new PointsLedgerService($ledgerRepository),
            $ledgerRepository,
        );

        $exception = $this->captureRuntime(
            fn () => $service->revoke((int) $request->id, 7, null, $this->at('12:01'), organizationId: 42),
        );

        self::assertSame('withdrawal_not_found', $exception->getMessage());
    }

    public function testRepositoryFailsWhenInsertedRowsDisappearBeforeHydration(): void
    {
        [$requestConnection, $requestRepository] = $this->fixture(42, 1_000);
        $requestConnection->executeStatement(
            <<<'SQL'
CREATE TRIGGER erase_withdrawal_request_after_insert
AFTER INSERT ON withdrawal_requests
BEGIN
    DELETE FROM withdrawal_requests WHERE id = NEW.id;
END
SQL,
        );
        $requestException = $this->captureRuntime(
            fn () => $requestRepository->createRequest(
                organizationId: 42,
                requestedByUserId: 7,
                pointsAmount: 400,
                amountCny: '4.00',
                pointsPerCny: 100,
                idempotencyKey: 'withdrawal:persistence:request',
                payoutMethod: 'bank_transfer',
                payoutAccount: ['account_no' => 'x'],
                notes: null,
                ledgerEntryId: (int) $requestConnection->fetchOne('SELECT id FROM ledger_entries ORDER BY id DESC LIMIT 1'),
                now: $this->at('12:00'),
            ),
        );
        self::assertSame('withdrawal_request_persistence_failed', $requestException->getMessage());
        self::assertSame(0, (int) $requestConnection->fetchOne('SELECT COUNT(*) FROM withdrawal_requests'));

        [$proofConnection, $proofRepository, $proofService] = $this->fixture(42, 1_000);
        $request = $proofService->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:persistence:proof', $this->at('12:01'));
        $proofConnection->executeStatement(
            <<<'SQL'
CREATE TRIGGER erase_withdrawal_proof_after_insert
AFTER INSERT ON withdrawal_proofs
BEGIN
    DELETE FROM withdrawal_proofs WHERE id = NEW.id;
END
SQL,
        );
        $proofException = $this->captureRuntime(
            fn () => $proofRepository->createProof(
                (int) $request->id,
                42,
                99,
                sprintf('withdrawals/42/%d/proof-persistence.pdf', $request->id),
                'application/pdf',
                1_024,
                $this->at('12:02'),
            ),
        );
        self::assertSame('withdrawal_proof_persistence_failed', $proofException->getMessage());
        self::assertSame(0, (int) $proofConnection->fetchOne('SELECT COUNT(*) FROM withdrawal_proofs'));
    }

    public function testRequestAndResubmitRollBackLedgerDebitsWhenStatePersistenceFails(): void
    {
        [$connection, , $service, $ledger] = $this->fixture(42, 1_000);
        $connection->executeStatement(
            <<<'SQL'
CREATE TRIGGER fail_withdrawal_request_insert
BEFORE INSERT ON withdrawal_requests
BEGIN
    SELECT RAISE(ABORT, 'forced request insert failure');
END
SQL,
        );
        try {
            $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:rollback:request', $this->at('12:00'));
            self::fail('Expected withdrawal insert failure.');
        } catch (\Throwable) {
            self::assertSame(1_000, $ledger->balanceForOrganization(42, 'publisher_earnings'));
        }
        $connection->executeStatement('DROP TRIGGER fail_withdrawal_request_insert');

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, 'withdrawal:rollback:resubmit', $this->at('12:01'));
        $service->revoke((int) $request->id, 7, null, $this->at('12:02'));
        $connection->executeStatement(
            <<<'SQL'
CREATE TRIGGER fail_withdrawal_resubmit
BEFORE UPDATE OF review_status ON withdrawal_requests
WHEN OLD.review_status = 'revoked' AND NEW.review_status = 'pending'
BEGIN
    SELECT RAISE(ABORT, 'forced resubmit failure');
END
SQL,
        );
        try {
            $service->resubmit((int) $request->id, 7, ['account_no' => 'new'], null, $this->at('12:03'));
            self::fail('Expected withdrawal resubmit failure.');
        } catch (\Throwable) {
            self::assertSame(1_000, $ledger->balanceForOrganization(42, 'publisher_earnings'));
            self::assertSame('revoked', (string) $connection->fetchOne('SELECT review_status FROM withdrawal_requests WHERE id = ?', [$request->id]));
        }
    }

    public function testRepositoryLocksOrganizationRowsForUpdateOnMySql(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('fetchAllAssociative')->willReturn([['id' => 42]]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT id FROM organizations WHERE id = ? FOR UPDATE', [42])
            ->willReturn($result);

        (new WithdrawalRepository($connection))->lockOrganizationForUpdate(42);
    }

    public function testRepositoryLocksRequestsAndProofsForUpdateOnMySql(): void
    {
        $queries = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::exactly(2))
            ->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$queries): false {
                $queries[] = [$sql, $parameters];

                return false;
            });
        $repository = new WithdrawalRepository($connection);

        self::assertNull($repository->findRequestForUpdate(41));
        self::assertNull($repository->findProofForUpdate(51, 41));
        self::assertSame([
            ['SELECT * FROM withdrawal_requests WHERE id = ? FOR UPDATE', [41]],
            ['SELECT * FROM withdrawal_proofs WHERE id = ? AND withdrawal_request_id = ? FOR UPDATE', [51, 41]],
        ], $queries);
    }

    private function installWithdrawalCompareAndSwapRace(
        Connection $connection,
        WithdrawalReviewStatus $oldReviewStatus,
        WithdrawalPaymentStatus $oldPaymentStatus,
        WithdrawalReviewStatus $attemptedReviewStatus,
        WithdrawalPaymentStatus $attemptedPaymentStatus,
        bool $concurrentWinner,
    ): void
    {
        $concurrentUpdate = $concurrentWinner
            ? <<<'SQL'
    UPDATE withdrawal_requests
    SET review_status = NEW.review_status,
        payment_status = NEW.payment_status,
        payout_account_json = NEW.payout_account_json,
        applicant_notes = NEW.applicant_notes,
        reviewer_user_id = NEW.reviewer_user_id,
        reviewer_notes = NEW.reviewer_notes,
        payment_proof_id = NEW.payment_proof_id,
        payment_completed_by_user_id = NEW.payment_completed_by_user_id,
        payment_notes = NEW.payment_notes,
        ledger_entry_id = NEW.ledger_entry_id,
        reviewed_at = NEW.reviewed_at,
        approved_at = NEW.approved_at,
        paid_at = NEW.paid_at,
        rejected_at = NEW.rejected_at,
        revoked_at = NEW.revoked_at,
        resubmitted_at = NEW.resubmitted_at
    WHERE id = OLD.id;
SQL
            : '';

        $connection->executeStatement(sprintf(
            <<<'SQL'
CREATE TRIGGER withdrawal_compare_and_swap_race
BEFORE UPDATE ON withdrawal_requests
WHEN OLD.review_status = '%s'
  AND OLD.payment_status = '%s'
  AND NEW.review_status = '%s'
  AND NEW.payment_status = '%s'
BEGIN
%s
    SELECT RAISE(IGNORE);
END
SQL,
            $oldReviewStatus->value,
            $oldPaymentStatus->value,
            $attemptedReviewStatus->value,
            $attemptedPaymentStatus->value,
            $concurrentUpdate,
        ));
    }

    /**
     * @return array{Connection, WithdrawalRepository, WithdrawalService, PointsLedgerRepository}
     */
    private function fixture(int $organizationId, int $balance): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit($organizationId, 'publisher_earnings', null, $balance, 'withdrawal:fixture:' . $organizationId);
        $repository = new WithdrawalRepository($connection);

        return [$connection, $repository, new WithdrawalService($repository, $ledger, $ledgerRepository), $ledgerRepository];
    }

    private function verifiedProof(
        WithdrawalRepository $repository,
        int $withdrawalRequestId,
        int $organizationId,
        int $uploadedByUserId,
        string $suffix,
        DateTimeImmutable $now,
    ): WithdrawalProof {
        $proof = $repository->createProof(
            $withdrawalRequestId,
            $organizationId,
            $uploadedByUserId,
            sprintf('withdrawals/%d/%d/proof-%s.pdf', $organizationId, $withdrawalRequestId, $suffix),
            'application/pdf',
            1_024,
            $now,
        );
        $verified = $repository->verifyProofIfPending(
            (int) $proof->id,
            'sha256:' . hash('sha256', $suffix),
            $now->modify('+1 second'),
        );
        self::assertInstanceOf(WithdrawalProof::class, $verified);

        return $verified;
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-10 ' . $time . ':00 UTC');
    }

    private function captureRuntime(callable $operation): RuntimeException
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        self::fail('Expected runtime exception.');
    }

    private function captureInvalidArgument(callable $operation): InvalidArgumentException
    {
        try {
            $operation();
        } catch (InvalidArgumentException $exception) {
            return $exception;
        }

        self::fail('Expected invalid argument exception.');
    }
}
