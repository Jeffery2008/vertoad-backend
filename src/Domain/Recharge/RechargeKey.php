<?php

declare(strict_types=1);

namespace VertoAD\Domain\Recharge;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RechargeKey
{
    /**
     * @param array<string, mixed>|null $batchMetadata
     */
    public function __construct(
        public ?int $id,
        public ?int $organizationId,
        public string $keyHash,
        public string $encryptedPlaintextKey,
        public int $pointsAmount,
        public RechargeKeyStatus $status,
        public ?string $batchCode,
        public ?array $batchMetadata,
        public ?DateTimeImmutable $expiresAt,
        public ?int $issuedByUserId,
        public ?int $redeemedByUserId,
        public ?int $redeemedLedgerEntryId,
        public ?DateTimeImmutable $redeemedAt,
    ) {
        if ($this->organizationId !== null && $this->organizationId <= 0) {
            throw new InvalidArgumentException('Recharge key organization ID must be positive when provided.');
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $this->keyHash)) {
            throw new InvalidArgumentException('Recharge key hash must be a SHA-256 hex digest.');
        }

        if ($this->encryptedPlaintextKey === '') {
            throw new InvalidArgumentException('Recharge key encrypted plaintext is required.');
        }

        if ($this->pointsAmount <= 0) {
            throw new InvalidArgumentException('Recharge key points amount must be positive.');
        }

        if ($this->issuedByUserId !== null && $this->issuedByUserId <= 0) {
            throw new InvalidArgumentException('Recharge key issuer user ID must be positive when provided.');
        }

        if ($this->redeemedByUserId !== null && $this->redeemedByUserId <= 0) {
            throw new InvalidArgumentException('Recharge key redeemer user ID must be positive when provided.');
        }

        if ($this->redeemedLedgerEntryId !== null && $this->redeemedLedgerEntryId <= 0) {
            throw new InvalidArgumentException('Recharge key ledger entry ID must be positive when provided.');
        }
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            organizationId: $this->organizationId,
            keyHash: $this->keyHash,
            encryptedPlaintextKey: $this->encryptedPlaintextKey,
            pointsAmount: $this->pointsAmount,
            status: $this->status,
            batchCode: $this->batchCode,
            batchMetadata: $this->batchMetadata,
            expiresAt: $this->expiresAt,
            issuedByUserId: $this->issuedByUserId,
            redeemedByUserId: $this->redeemedByUserId,
            redeemedLedgerEntryId: $this->redeemedLedgerEntryId,
            redeemedAt: $this->redeemedAt,
        );
    }

    public function withStatus(RechargeKeyStatus $status): self
    {
        return new self(
            id: $this->id,
            organizationId: $this->organizationId,
            keyHash: $this->keyHash,
            encryptedPlaintextKey: $this->encryptedPlaintextKey,
            pointsAmount: $this->pointsAmount,
            status: $status,
            batchCode: $this->batchCode,
            batchMetadata: $this->batchMetadata,
            expiresAt: $this->expiresAt,
            issuedByUserId: $this->issuedByUserId,
            redeemedByUserId: $this->redeemedByUserId,
            redeemedLedgerEntryId: $this->redeemedLedgerEntryId,
            redeemedAt: $this->redeemedAt,
        );
    }

    public function withRedemption(
        int $organizationId,
        int $redeemedByUserId,
        int $ledgerEntryId,
        DateTimeImmutable $redeemedAt,
    ): self {
        return new self(
            id: $this->id,
            organizationId: $organizationId,
            keyHash: $this->keyHash,
            encryptedPlaintextKey: $this->encryptedPlaintextKey,
            pointsAmount: $this->pointsAmount,
            status: RechargeKeyStatus::Redeemed,
            batchCode: $this->batchCode,
            batchMetadata: $this->batchMetadata,
            expiresAt: $this->expiresAt,
            issuedByUserId: $this->issuedByUserId,
            redeemedByUserId: $redeemedByUserId,
            redeemedLedgerEntryId: $ledgerEntryId,
            redeemedAt: $redeemedAt,
        );
    }
}
