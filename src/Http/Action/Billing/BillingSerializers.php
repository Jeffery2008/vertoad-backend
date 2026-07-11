<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use VertoAD\Domain\Ledger\PointsLedgerEntry;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Billing\WithdrawalProof;
use VertoAD\Domain\Billing\WithdrawalProofUploadIntent;
use VertoAD\Domain\Billing\WithdrawalRequest;

final class BillingSerializers
{
    /**
     * @return array<string, mixed>
     */
    public static function ledgerEntry(PointsLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'organization_id' => $entry->organizationId,
            'account_type' => $entry->accountType,
            'account_id' => $entry->accountId,
            'points_amount' => $entry->pointsAmount,
            'direction' => $entry->direction->value,
            'balance_after_points' => $entry->balanceAfterPoints,
            'reference_type' => $entry->referenceType,
            'reference_id' => $entry->referenceId,
            'memo' => $entry->memo,
            'metadata' => $entry->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function withdrawalRequest(WithdrawalRequest $request): array
    {
        return [
            'id' => $request->id,
            'organization_id' => $request->organizationId,
            'requested_by_user_id' => $request->requestedByUserId,
            'points_amount' => $request->pointsAmount,
            'amount_cny' => $request->amountCny,
            'points_per_cny' => $request->pointsPerCny,
            'currency' => 'CNY',
            'idempotency_key' => $request->idempotencyKey,
            'review_status' => $request->reviewStatus->value,
            'payment_status' => $request->paymentStatus->value,
            'payout_method' => $request->payoutMethod,
            'payout_account' => $request->payoutAccount,
            'applicant_notes' => $request->applicantNotes,
            'reviewer_user_id' => $request->reviewerUserId,
            'reviewer_notes' => $request->reviewerNotes,
            'payment_proof_id' => $request->paymentProofId,
            'payment_completed_by_user_id' => $request->paymentCompletedByUserId,
            'payment_notes' => $request->paymentNotes,
            'ledger_entry_id' => $request->ledgerEntryId,
            'requested_at' => $request->requestedAt->format('Y-m-d H:i:s'),
            'reviewed_at' => $request->reviewedAt?->format('Y-m-d H:i:s'),
            'approved_at' => $request->approvedAt?->format('Y-m-d H:i:s'),
            'paid_at' => $request->paidAt?->format('Y-m-d H:i:s'),
            'rejected_at' => $request->rejectedAt?->format('Y-m-d H:i:s'),
            'revoked_at' => $request->revokedAt?->format('Y-m-d H:i:s'),
            'resubmitted_at' => $request->resubmittedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<WithdrawalRequest> $requests
     * @return list<array<string, mixed>>
     */
    public static function withdrawalRequests(array $requests): array
    {
        return array_map(self::withdrawalRequest(...), $requests);
    }

    /**
     * @return array<string, mixed>
     */
    public static function withdrawalProof(WithdrawalProof $proof): array
    {
        return [
            'id' => $proof->id,
            'withdrawal_request_id' => $proof->withdrawalRequestId,
            'organization_id' => $proof->organizationId,
            'uploaded_by_user_id' => $proof->uploadedByUserId,
            'object_key' => $proof->objectKey,
            'content_type' => $proof->contentType,
            'byte_size' => $proof->byteSize,
            'checksum' => $proof->checksum,
            'status' => $proof->status->value,
            'verification_error_code' => $proof->verificationErrorCode,
            'created_at' => $proof->createdAt->format('Y-m-d H:i:s'),
            'verification_attempted_at' => $proof->verificationAttemptedAt?->format('Y-m-d H:i:s'),
            'verified_at' => $proof->verifiedAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param list<WithdrawalProof> $proofs
     * @return list<array<string, mixed>>
     */
    public static function withdrawalProofs(array $proofs): array
    {
        return array_map(self::withdrawalProof(...), $proofs);
    }

    /**
     * @return array<string, mixed>
     */
    public static function withdrawalProofIntent(WithdrawalProofUploadIntent $intent): array
    {
        return [
            'proof' => self::withdrawalProof($intent->proof),
            'upload' => [
                'url' => $intent->upload->url,
                'method' => $intent->upload->method,
                'object_key' => $intent->upload->objectKey,
                'status_code' => $intent->upload->statusCode,
                'headers' => $intent->upload->headers,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rechargeKey(RechargeKey $key, ?string $plaintextKey = null): array
    {
        $payload = [
            'id' => $key->id,
            'organization_id' => $key->organizationId,
            'points_amount' => $key->pointsAmount,
            'status' => $key->status->value,
            'batch_code' => $key->batchCode,
            'batch_metadata' => $key->batchMetadata,
            'expires_at' => $key->expiresAt?->format('Y-m-d H:i:s'),
            'issued_by_user_id' => $key->issuedByUserId,
            'redeemed_by_user_id' => $key->redeemedByUserId,
            'redeemed_ledger_entry_id' => $key->redeemedLedgerEntryId,
            'redeemed_at' => $key->redeemedAt?->format('Y-m-d H:i:s'),
        ];

        if ($plaintextKey !== null) {
            $payload['plaintext_key'] = $plaintextKey;
        }

        return $payload;
    }
}
