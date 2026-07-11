<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;

final readonly class WithdrawalRequest
{
    /**
     * @param array<string, mixed> $payoutAccount
     */
    public function __construct(
        public ?int $id,
        public int $organizationId,
        public int $requestedByUserId,
        public int $pointsAmount,
        public string $amountCny,
        public int $pointsPerCny,
        public string $idempotencyKey,
        public WithdrawalReviewStatus $reviewStatus,
        public WithdrawalPaymentStatus $paymentStatus,
        public string $payoutMethod,
        public array $payoutAccount,
        public ?string $applicantNotes,
        public ?int $reviewerUserId,
        public ?string $reviewerNotes,
        public ?int $paymentProofId,
        public ?int $paymentCompletedByUserId,
        public ?string $paymentNotes,
        public int $ledgerEntryId,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $reviewedAt,
        public ?DateTimeImmutable $approvedAt,
        public ?DateTimeImmutable $paidAt,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $resubmittedAt,
    ) {
    }
}
