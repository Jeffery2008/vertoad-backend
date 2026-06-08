<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Billing;

use VertoAD\Domain\Ledger\PointsLedgerEntry;
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
            'status' => $request->status->value,
            'payout_method' => $request->payoutMethod,
            'payout_account' => $request->payoutAccount,
            'applicant_notes' => $request->applicantNotes,
            'reviewer_user_id' => $request->reviewerUserId,
            'reviewer_notes' => $request->reviewerNotes,
            'ledger_entry_id' => $request->ledgerEntryId,
        ];
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
            'status' => $proof->status,
        ];
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
}
