<?php

declare(strict_types=1);

namespace VertoAD\Service;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Recharge\RechargeKeyPlaintext;
use VertoAD\Domain\Recharge\RechargeKeyRedemption;
use VertoAD\Domain\Recharge\RechargeKeyStatus;
use VertoAD\Repository\RechargeKeyRepositoryInterface;

final class RechargeKeyService
{
    public function __construct(
        private readonly RechargeKeyRepositoryInterface $repository,
        private readonly PointsLedgerService $ledger,
        private readonly RechargeKeyPlaintextCipherInterface $cipher,
        private readonly ?Closure $plaintextGenerator = null,
        private readonly ?AuditLogService $audit = null,
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

    /**
     * @param array<string, mixed>|null $batchMetadata
     * @return list<RechargeKeyPlaintext>
     */
    public function generateBatch(
        int $pointsAmount,
        int $count,
        ?string $batchCode,
        ?array $batchMetadata,
        ?DateTimeImmutable $expiresAt,
        int $issuedByUserId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        if ($count < 1 || $count > 500) {
            throw new InvalidArgumentException('Recharge key batch count must be between 1 and 500.');
        }

        if ($issuedByUserId <= 0) {
            throw new InvalidArgumentException('Recharge key issuer user ID must be positive.');
        }

        if ($this->audit === null) {
            throw new RuntimeException('Audit log service is required to generate recharge key batches.');
        }

        return $this->repository->transactional(function () use (
            $pointsAmount,
            $count,
            $batchCode,
            $batchMetadata,
            $expiresAt,
            $issuedByUserId,
            $ipAddress,
            $userAgent,
        ): array {
            $generated = [];
            for ($index = 0; $index < $count; $index++) {
                $generated[] = $this->issueGeneratedKey(
                    pointsAmount: $pointsAmount,
                    batchCode: $batchCode,
                    batchMetadata: $batchMetadata,
                    expiresAt: $expiresAt,
                    issuedByUserId: $issuedByUserId,
                );
            }

            $this->audit->record(
                action: 'billing.recharge_key.generate_batch',
                subjectType: 'recharge_key_batch',
                actorUserId: $issuedByUserId,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                metadata: [
                    'batch_code' => $this->normalizeNullableText($batchCode),
                    'count' => count($generated),
                    'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
                    'generated_key_ids' => array_map(
                        static fn (RechargeKeyPlaintext $generatedKey): ?int => $generatedKey->key->id,
                        $generated,
                    ),
                    'points_amount' => $pointsAmount,
                ],
            );

            return $generated;
        });
    }

    public function revealPlaintext(
        int $keyId,
        int $actorUserId,
        ?string $ipAddress,
        ?string $userAgent,
    ): RechargeKeyPlaintext {
        if ($this->audit === null) {
            throw new RuntimeException('Audit log service is required to reveal recharge key plaintext.');
        }

        if ($keyId <= 0) {
            throw new InvalidArgumentException('Recharge key ID must be positive.');
        }

        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('Recharge key plaintext reveal actor user ID must be positive.');
        }

        $key = $this->repository->findById($keyId);
        if ($key === null) {
            throw new RuntimeException('Recharge key was not found.');
        }

        $this->audit->record(
            action: 'billing.recharge_key.reveal_plaintext',
            subjectType: 'recharge_key',
            subjectId: $key->id,
            actorUserId: $actorUserId,
            organizationId: $key->organizationId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            metadata: [
                'batch_code' => $key->batchCode,
                'points_amount' => $key->pointsAmount,
                'status' => $key->status->value,
                'redeemed_by_user_id' => $key->redeemedByUserId,
            ],
        );

        return new RechargeKeyPlaintext($key, $this->cipher->decrypt($key->encryptedPlaintextKey));
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

    private function issueGeneratedKey(
        int $pointsAmount,
        ?string $batchCode,
        ?array $batchMetadata,
        ?DateTimeImmutable $expiresAt,
        int $issuedByUserId,
    ): RechargeKeyPlaintext {
        $attemptsRemaining = 20;
        while ($attemptsRemaining > 0) {
            $plaintext = $this->normalizePlaintext($this->generatePlaintext());
            try {
                $key = $this->issue(
                    plaintextKey: $plaintext,
                    pointsAmount: $pointsAmount,
                    batchCode: $batchCode,
                    batchMetadata: $batchMetadata,
                    expiresAt: $expiresAt,
                    issuedByUserId: $issuedByUserId,
                    organizationId: null,
                );

                return new RechargeKeyPlaintext($key, $plaintext);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'Recharge key already exists.') {
                    throw $exception;
                }
            }

            $attemptsRemaining--;
        }

        throw new RuntimeException('Unable to generate a unique recharge key after repeated attempts.');
    }

    private function generatePlaintext(): string
    {
        if ($this->plaintextGenerator !== null) {
            return ($this->plaintextGenerator)();
        }

        return 'rk_live_' . strtoupper(bin2hex(random_bytes(24)));
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
