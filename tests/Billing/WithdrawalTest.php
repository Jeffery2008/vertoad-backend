<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Billing\WithdrawalStatus;
use VertoAD\Repository\Billing\WithdrawalRepository;
use VertoAD\Repository\PointsLedgerRepository;
use VertoAD\Service\Billing\WithdrawalService;
use VertoAD\Service\PointsLedgerService;

final class WithdrawalTest extends TestCase
{
    public function testWithdrawalLifecycleDebitsOnRequestAndAuditsPaidRejectedRevokedResubmittedStates(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 5000, 'earning:seed');

        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(
            organizationId: 42,
            requestedByUserId: 7,
            pointsAmount: 2500,
            payoutMethod: 'bank_transfer',
            payoutAccount: ['account_name' => 'Publisher Ltd', 'account_no' => '****1234'],
            notes: 'June earnings',
            now: new DateTimeImmutable('2026-06-08 12:00:00'),
        );
        self::assertSame('requested', $request->status->value);
        self::assertSame(2500, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $paid = $service->markPaid($request->id ?? 0, 99, 'paid manually', new DateTimeImmutable('2026-06-08 13:00:00'));
        self::assertSame('paid', $paid->status->value);

        $rejectedRequest = $service->requestWithdrawal(42, 7, 500, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 14:00:00'));
        $rejected = $service->reject($rejectedRequest->id ?? 0, 99, 'bad account', new DateTimeImmutable('2026-06-08 14:30:00'));
        self::assertSame('rejected', $rejected->status->value);
        self::assertSame(2500, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $revokedRequest = $service->requestWithdrawal(42, 7, 300, 'bank_transfer', ['account_no' => 'y'], null, new DateTimeImmutable('2026-06-08 15:00:00'));
        $revoked = $service->revoke($revokedRequest->id ?? 0, 7, 'user cancelled', new DateTimeImmutable('2026-06-08 15:05:00'));
        self::assertSame('revoked', $revoked->status->value);
        self::assertSame(2500, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $resubmitted = $service->resubmit(
            $revokedRequest->id ?? 0,
            7,
            ['account_no' => 'z'],
            'fixed account',
            new DateTimeImmutable('2026-06-08 15:10:00'),
        );
        self::assertSame('requested', $resubmitted->status->value);
        self::assertSame(2200, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $actions = array_column($connection->fetchAllAssociative('SELECT action FROM withdrawal_audit_events ORDER BY id'), 'action');
        self::assertSame([
            'requested',
            'paid',
            'requested',
            'rejected',
            'requested',
            'revoked',
            'resubmitted',
        ], $actions);
    }

    public function testWithdrawalRequestRejectsInsufficientPublisherEarningsAndIllegalTransitions(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 100, 'earning:small');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $insufficient = $this->captureValidation(
            fn () => $service->requestWithdrawal(42, 7, 101, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00')),
        );
        self::assertSame('insufficient_publisher_earnings', $insufficient->getMessage());

        $request = $service->requestWithdrawal(42, 7, 100, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:01:00'));
        $paid = $service->markPaid($request->id ?? 0, 99, null, new DateTimeImmutable('2026-06-08 12:02:00'));
        self::assertSame('paid', $paid->status->value);

        $illegal = $this->captureValidation(fn () => $service->reject($request->id ?? 0, 99, 'late reject', new DateTimeImmutable('2026-06-08 12:03:00')));
        self::assertSame('withdrawal_transition_not_allowed', $illegal->getMessage());

        $resubmitIllegal = $this->captureValidation(
            fn () => $service->resubmit($request->id ?? 0, 7, ['account_no' => 'z'], null, new DateTimeImmutable('2026-06-08 12:04:00')),
        );
        self::assertSame('withdrawal_transition_not_allowed', $resubmitIllegal->getMessage());
    }

    public function testWithdrawalRepositoryAndServiceValidateInvalidInputs(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new WithdrawalRepository($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $service = new WithdrawalService($repository, new PointsLedgerService($ledgerRepository), $ledgerRepository);

        self::assertNull($repository->findRequest(0));
        self::assertNull($repository->findProof(0));

        try {
            $service->requestWithdrawal(0, 7, 100, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Withdrawal request is invalid.', $exception->getMessage());
            return;
        }

        self::fail('Expected invalid withdrawal request.');
    }

    public function testResubmitRejectsInsufficientBalanceAfterRevocation(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 300, 'earning:resubmit-small');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(42, 7, 300, 'bank_transfer', ['account_no' => 'x'], '   ', new DateTimeImmutable('2026-06-08 12:00:00'));
        $service->revoke($request->id ?? 0, 7, '   ', new DateTimeImmutable('2026-06-08 12:01:00'));
        $ledger->debit(42, 'publisher_earnings', null, 300, 'external:publisher-adjustment');

        $insufficient = $this->captureValidation(
            fn () => $service->resubmit($request->id ?? 0, 7, ['account_no' => 'y'], null, new DateTimeImmutable('2026-06-08 12:02:00')),
        );

        self::assertSame('insufficient_publisher_earnings', $insufficient->getMessage());
    }

    public function testResubmitRollsBackLedgerDebitWhenStateUpdateFails(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:resubmit-rollback');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $service->revoke($request->id ?? 0, 7, 'cancelled', new DateTimeImmutable('2026-06-08 12:01:00'));
        $connection->executeStatement(
            <<<'SQL'
CREATE TRIGGER fail_withdrawal_resubmit_update
BEFORE UPDATE OF status ON withdrawal_requests
WHEN OLD.status = 'revoked' AND NEW.status = 'requested'
BEGIN
    SELECT RAISE(ABORT, 'forced withdrawal resubmit failure');
END
SQL
        );

        try {
            $service->resubmit(
                $request->id ?? 0,
                7,
                ['account_no' => 'z'],
                'fixed account',
                new DateTimeImmutable('2026-06-08 12:02:00'),
            );
        } catch (\Throwable) {
            self::assertSame(1000, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
            self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
            self::assertSame('revoked', (string) $connection->fetchOne('SELECT status FROM withdrawal_requests WHERE id = ?', [$request->id]));
            return;
        }

        self::fail('Expected withdrawal resubmit update failure.');
    }

    public function testRequestWithdrawalRollsBackLedgerDebitWhenWithdrawalInsertFails(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:rollback-seed');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $connection->executeStatement(
            <<<'SQL'
CREATE TRIGGER fail_withdrawal_request_insert
BEFORE INSERT ON withdrawal_requests
BEGIN
    SELECT RAISE(ABORT, 'forced withdrawal insert failure');
END
SQL
        );

        try {
            $service->requestWithdrawal(
                organizationId: 42,
                requestedByUserId: 7,
                pointsAmount: 400,
                payoutMethod: 'bank_transfer',
                payoutAccount: ['account_no' => 'x'],
                notes: null,
                now: new DateTimeImmutable('2026-06-08 12:00:00'),
            );
        } catch (\Throwable) {
            self::assertSame(1000, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM withdrawal_requests'));
            return;
        }

        self::fail('Expected withdrawal insert failure.');
    }

    public function testRepeatedRejectAndRevokeReturnFinalStateWithoutDuplicateLedgerRestore(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:idempotent-status');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $rejectedRequest = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $firstReject = $service->reject($rejectedRequest->id ?? 0, 99, 'bad account', new DateTimeImmutable('2026-06-08 12:01:00'));
        $secondReject = $service->reject($rejectedRequest->id ?? 0, 99, 'bad account', new DateTimeImmutable('2026-06-08 12:02:00'));

        self::assertSame('rejected', $firstReject->status->value);
        self::assertSame('rejected', $secondReject->status->value);
        self::assertSame(1000, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));

        $revokedRequest = $service->requestWithdrawal(42, 7, 300, 'bank_transfer', ['account_no' => 'y'], null, new DateTimeImmutable('2026-06-08 12:03:00'));
        $firstRevoke = $service->revoke($revokedRequest->id ?? 0, 7, 'cancelled', new DateTimeImmutable('2026-06-08 12:04:00'));
        $secondRevoke = $service->revoke($revokedRequest->id ?? 0, 7, 'cancelled', new DateTimeImmutable('2026-06-08 12:05:00'));

        self::assertSame('revoked', $firstRevoke->status->value);
        self::assertSame('revoked', $secondRevoke->status->value);
        self::assertSame(1000, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'rejected'"));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'revoked'"));
    }

    public function testRepeatedMarkPaidReturnsFinalStateWithoutDuplicateAudit(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:idempotent-paid');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $firstPaid = $service->markPaid($request->id ?? 0, 99, 'paid', new DateTimeImmutable('2026-06-08 12:01:00'));
        $secondPaid = $service->markPaid($request->id ?? 0, 99, 'paid', new DateTimeImmutable('2026-06-08 12:02:00'));

        self::assertSame('paid', $firstPaid->status->value);
        self::assertSame('paid', $secondPaid->status->value);
        self::assertSame(600, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'paid'"));
    }

    public function testRepositoryStateUpdateRequiresExpectedOldStatus(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $repository = new WithdrawalRepository($connection);

        $request = $repository->createRequest(
            organizationId: 42,
            requestedByUserId: 7,
            pointsAmount: 500,
            payoutMethod: 'bank_transfer',
            payoutAccount: ['account_no' => 'x'],
            notes: null,
            ledgerEntryId: 1,
            now: new DateTimeImmutable('2026-06-08 12:00:00'),
        );

        $updated = $repository->updateRequestStateIfCurrent(
            id: $request->id ?? 0,
            expectedStatus: WithdrawalStatus::Requested,
            status: WithdrawalStatus::Rejected,
            reviewerUserId: 99,
            reviewerNotes: 'bad account',
            payoutAccount: null,
            now: new DateTimeImmutable('2026-06-08 12:01:00'),
        );
        self::assertSame('rejected', $updated?->status->value);

        $staleUpdate = $repository->updateRequestStateIfCurrent(
            id: $request->id ?? 0,
            expectedStatus: WithdrawalStatus::Requested,
            status: WithdrawalStatus::Revoked,
            reviewerUserId: null,
            reviewerNotes: 'late cancel',
            payoutAccount: null,
            now: new DateTimeImmutable('2026-06-08 12:02:00'),
        );

        self::assertNull($staleUpdate);
        self::assertSame('rejected', $repository->findRequest($request->id ?? 0)?->status->value);
    }

    public function testRepositoryLocksOrganizationRowsForUpdateOnSqlDatabases(): void
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

    public function testMarkPaidRejectsWhenCompareAndSwapMissSeesDifferentTerminalState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:stale-transition');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $this->installWithdrawalStatusRaceTrigger($connection, 'requested', 'paid', 'rejected');
        $stale = $this->captureValidation(fn () => $service->markPaid($request->id ?? 0, 99, 'paid', new DateTimeImmutable('2026-06-08 12:01:00')));

        self::assertSame('withdrawal_transition_not_allowed', $stale->getMessage());
        self::assertSame(600, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame('requested', (string) $connection->fetchOne('SELECT status FROM withdrawal_requests WHERE id = ?', [$request->id]));
    }

    public function testMarkPaidReturnsCurrentStateWhenCompareAndSwapMissAlreadyReachedTarget(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:stale-target');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $this->installWithdrawalStatusRaceTrigger($connection, 'requested', 'paid', 'paid');
        $paid = $service->markPaid($request->id ?? 0, 99, 'paid', new DateTimeImmutable('2026-06-08 12:01:00'));

        self::assertSame('paid', $paid->status->value);
        self::assertSame(600, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'requested'"));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'paid'"));
    }

    public function testResubmitRejectsWhenCompareAndSwapMissesRevokedState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        BillingTask14Schema::create($connection);
        $ledgerRepository = new PointsLedgerRepository($connection);
        $ledger = new PointsLedgerService($ledgerRepository);
        $ledger->credit(42, 'publisher_earnings', null, 1000, 'earning:stale-resubmit');
        $service = new WithdrawalService(new WithdrawalRepository($connection), $ledger, $ledgerRepository);

        $request = $service->requestWithdrawal(42, 7, 400, 'bank_transfer', ['account_no' => 'x'], null, new DateTimeImmutable('2026-06-08 12:00:00'));
        $service->revoke($request->id ?? 0, 7, 'cancelled', new DateTimeImmutable('2026-06-08 12:01:00'));
        $this->installWithdrawalStatusRaceTrigger($connection, 'revoked', 'requested', 'paid');

        $stale = $this->captureValidation(fn () => $service->resubmit($request->id ?? 0, 7, ['account_no' => 'y'], null, new DateTimeImmutable('2026-06-08 12:02:00')));

        self::assertSame('withdrawal_transition_not_allowed', $stale->getMessage());
        self::assertSame(1000, $ledgerRepository->balanceForOrganization(42, 'publisher_earnings'));
        self::assertSame('revoked', (string) $connection->fetchOne('SELECT status FROM withdrawal_requests WHERE id = ?', [$request->id]));
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM withdrawal_audit_events WHERE action = 'resubmitted'"));
    }

    private function installWithdrawalStatusRaceTrigger(
        \Doctrine\DBAL\Connection $connection,
        string $currentStatus,
        string $attemptedStatus,
        string $concurrentStatus,
    ): void
    {
        $connection->executeStatement(sprintf(
            <<<'SQL'
CREATE TRIGGER withdrawal_status_race
BEFORE UPDATE OF status ON withdrawal_requests
WHEN OLD.status = '%s' AND NEW.status = '%s'
BEGIN
    UPDATE withdrawal_requests SET status = '%s' WHERE id = OLD.id;
    SELECT RAISE(IGNORE);
END
SQL,
            $currentStatus,
            $attemptedStatus,
            $concurrentStatus,
        ));
    }

    private function captureValidation(callable $operation): \RuntimeException
    {
        try {
            $operation();
        } catch (\RuntimeException $exception) {
            return $exception;
        }

        self::fail('Expected withdrawal validation failure.');
    }
}
