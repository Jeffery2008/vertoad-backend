<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Recharge\RechargeKeyRedemption;
use VertoAD\Domain\Recharge\RechargeKeyStatus;
use VertoAD\Repository\RechargeKeyRepositoryInterface;

final class RechargeKeyService
{
    public function __construct(
        private readonly RechargeKeyRepositoryInterface $repository,
        private readonly PointsLedgerService $ledger,
        private readonly RechargeKeyPlaintextCipherInterface $cipher,
    ) {
    }

    /**
     * @param array<string, mixed>|null $batchMetadata
     */
    public function issue(
        string $plaintextKey,
        int $pointsAmount,
        ?string $batchCode,
        ?array $batchMetadata,
        ?DateTimeImmutable $expiresAt,
        ?int $issuedByUserId,
        ?int $organizationId,
    ): RechargeKey {
        $plaintextKey = $this->normalizePlaintext($plaintextKey);
        if ($pointsAmount <= 0) {
            throw new InvalidArgumentException('Recharge key points amount must be positive.');
        }

        $keyHash = $this->hashPlaintext($plaintextKey);
        if ($this->repository->findByKeyHash($keyHash) !== null) {
            throw new RuntimeException('Recharge key already exists.');
        }

        return $this->repository->store(new RechargeKey(
            id: null,
            organizationId: $organizationId,
            keyHash: $keyHash,
            encryptedPlaintextKey: $this->cipher->encrypt($plaintextKey),
            pointsAmount: $pointsAmount,
            status: RechargeKeyStatus::Issued,
            batchCode: $this->normalizeNullableText($batchCode),
            batchMetadata: $this->normalizeMetadata($batchMetadata),
            expiresAt: $expiresAt,
            issuedByUserId: $issuedByUserId,
            redeemedByUserId: null,
            redeemedLedgerEntryId: null,
            redeemedAt: null,
        ));
    }

    public function redeem(
        string $plaintextKey,
        int $organizationId,
        int $redeemedByUserId,
        DateTimeImmutable $now,
    ): RechargeKeyRedemption {
        if ($organizationId <= 0) {
            throw new InvalidArgumentException('Recharge redemption organization ID must be positive.');
        }

        if ($redeemedByUserId <= 0) {
            throw new InvalidArgumentException('Recharge redemption user ID must be positive.');
        }

        $key = $this->repository->findByKeyHash($this->hashPlaintext($this->normalizePlaintext($plaintextKey)));
        if ($key === null) {
            throw new RuntimeException('Recharge key was not found.');
        }

        if ($key->status === RechargeKeyStatus::Revoked) {
            throw new RuntimeException('Recharge key has been revoked.');
        }

        if ($key->status === RechargeKeyStatus::Expired) {
            throw new RuntimeException('Recharge key has expired.');
        }

        if ($key->status === RechargeKeyStatus::Redeemed) {
            if ($key->organizationId === $organizationId && $key->redeemedByUserId === $redeemedByUserId) {
                return new RechargeKeyRedemption(
                    key: $key,
                    ledgerEntry: $this->creditLedger($key, $organizationId, $redeemedByUserId),
                );
            }

            throw new RuntimeException('Recharge key has already been redeemed.');
        }

        if ($key->expiresAt !== null && $key->expiresAt <= $now) {
            $this->repository->markExpired($key);
            throw new RuntimeException('Recharge key has expired.');
        }

        $ledgerEntry = $this->creditLedger($key, $organizationId, $redeemedByUserId);
        if ($ledgerEntry->id === null) {
            throw new RuntimeException('Recharge redemption ledger entry ID is required.');
        }

        $redeemedKey = $this->repository->markRedeemed(
            key: $key,
            organizationId: $organizationId,
            redeemedByUserId: $redeemedByUserId,
            ledgerEntryId: $ledgerEntry->id,
            redeemedAt: $now,
        );

        return new RechargeKeyRedemption($redeemedKey, $ledgerEntry);
    }

    private function creditLedger(RechargeKey $key, int $organizationId, int $redeemedByUserId): \VertoAD\Domain\Ledger\PointsLedgerEntry
    {
        if ($key->id === null) {
            throw new RuntimeException('Recharge key ID is required for redemption.');
        }

        return $this->ledger->credit(
            organizationId: $organizationId,
            accountType: 'advertiser_balance',
            accountId: null,
            pointsAmount: $key->pointsAmount,
            idempotencyKey: 'recharge_key:' . $key->id . ':redeem',
            referenceType: 'recharge_key',
            referenceId: $key->id,
            memo: 'Recharge key redemption',
            metadata: [
                'batch_code' => $key->batchCode,
                'entry_kind' => 'recharge_redemption',
                'redeemed_by_user_id' => $redeemedByUserId,
            ],
        );
    }

    private function normalizePlaintext(string $plaintextKey): string
    {
        $plaintextKey = trim($plaintextKey);
        if ($plaintextKey === '') {
            throw new InvalidArgumentException('Recharge key plaintext is required.');
        }

        return $plaintextKey;
    }

    private function hashPlaintext(string $plaintextKey): string
    {
        return hash('sha256', $plaintextKey);
    }

    private function normalizeNullableText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>|null
     */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        ksort($metadata);
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = $this->normalizeMetadata($value);
            }
        }

        return $metadata;
    }
}
