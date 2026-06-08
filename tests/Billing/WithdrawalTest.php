<?php

declare(strict_types=1);

namespace VertoAD\Tests\Billing;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
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
