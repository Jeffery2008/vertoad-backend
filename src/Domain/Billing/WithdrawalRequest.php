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
        public WithdrawalStatus $status,
        public string $payoutMethod,
        public array $payoutAccount,
        public ?string $applicantNotes,
        public ?int $reviewerUserId,
        public ?string $reviewerNotes,
        public int $ledgerEntryId,
        public DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $reviewedAt,
        public ?DateTimeImmutable $paidAt,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $resubmittedAt,
    ) {
    }
}
