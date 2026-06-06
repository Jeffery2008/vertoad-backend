<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Recharge\RechargeKeyStatus;

final class RechargeKeyRepository implements RechargeKeyRepositoryInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function store(RechargeKey $key): RechargeKey
    {
        $this->connection->insert(
            'recharge_keys',
            [
                'organization_id' => $key->organizationId,
                'key_hash' => $key->keyHash,
                'encrypted_plaintext_key' => $key->encryptedPlaintextKey,
                'points_amount' => $key->pointsAmount,
                'status' => $key->status->value,
                'batch_code' => $key->batchCode,
                'batch_metadata_json' => $key->batchMetadata === null
                    ? null
                    : json_encode($key->batchMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'issued_by_user_id' => $key->issuedByUserId,
                'redeemed_by_user_id' => $key->redeemedByUserId,
                'redeemed_ledger_entry_id' => $key->redeemedLedgerEntryId,
                'expires_at' => $this->formatDateTime($key->expiresAt),
                'redeemed_at' => $this->formatDateTime($key->redeemedAt),
            ],
            [
                'organization_id' => $key->organizationId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'key_hash' => ParameterType::STRING,
                'encrypted_plaintext_key' => ParameterType::STRING,
                'points_amount' => ParameterType::INTEGER,
                'status' => ParameterType::STRING,
                'batch_code' => $key->batchCode === null ? ParameterType::NULL : ParameterType::STRING,
                'batch_metadata_json' => $key->batchMetadata === null ? ParameterType::NULL : ParameterType::STRING,
                'issued_by_user_id' => $key->issuedByUserId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'redeemed_by_user_id' => $key->redeemedByUserId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'redeemed_ledger_entry_id' => $key->redeemedLedgerEntryId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'expires_at' => $key->expiresAt === null ? ParameterType::NULL : ParameterType::STRING,
                'redeemed_at' => $key->redeemedAt === null ? ParameterType::NULL : ParameterType::STRING,
            ],
        );

        $id = (int) $this->connection->lastInsertId();

        return $id > 0 ? $key->withId($id) : $key;
    }

    public function findByKeyHash(string $keyHash): ?RechargeKey
    {
        $keyHash = trim($keyHash);
        if ($keyHash === '') {
            return null;
        }

        $row = $this->connection->createQueryBuilder()
            ->select(
                'id',
                'organization_id',
                'key_hash',
                'encrypted_plaintext_key',
                'points_amount',
                'status',
                'batch_code',
                'batch_metadata_json',
                'issued_by_user_id',
                'redeemed_by_user_id',
                'redeemed_ledger_entry_id',
                'expires_at',
                'redeemed_at',
            )
            ->from('recharge_keys')
            ->where('key_hash = :key_hash')
            ->setParameter('key_hash', $keyHash)
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function markExpired(RechargeKey $key): RechargeKey
    {
        $this->connection->update(
            'recharge_keys',
            ['status' => RechargeKeyStatus::Expired->value],
            ['id' => $key->id],
            ['status' => ParameterType::STRING, 'id' => ParameterType::INTEGER],
        );

        return $key->withStatus(RechargeKeyStatus::Expired);
    }

    public function markRedeemed(
        RechargeKey $key,
        int $organizationId,
        int $redeemedByUserId,
        int $ledgerEntryId,
        DateTimeImmutable $redeemedAt,
    ): RechargeKey {
        $this->connection->update(
            'recharge_keys',
            [
                'organization_id' => $organizationId,
                'status' => RechargeKeyStatus::Redeemed->value,
                'redeemed_by_user_id' => $redeemedByUserId,
                'redeemed_ledger_entry_id' => $ledgerEntryId,
                'redeemed_at' => $this->formatDateTime($redeemedAt),
            ],
            ['id' => $key->id],
            [
                'organization_id' => ParameterType::INTEGER,
                'status' => ParameterType::STRING,
                'redeemed_by_user_id' => ParameterType::INTEGER,
                'redeemed_ledger_entry_id' => ParameterType::INTEGER,
                'redeemed_at' => ParameterType::STRING,
                'id' => ParameterType::INTEGER,
            ],
        );

        return $key->withRedemption($organizationId, $redeemedByUserId, $ledgerEntryId, $redeemedAt);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RechargeKey
    {
        $metadata = null;
        if ($row['batch_metadata_json'] !== null) {
            $decoded = json_decode((string) $row['batch_metadata_json'], true, flags: JSON_THROW_ON_ERROR);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        return new RechargeKey(
            id: (int) $row['id'],
            organizationId: $row['organization_id'] === null ? null : (int) $row['organization_id'],
            keyHash: (string) $row['key_hash'],
            encryptedPlaintextKey: (string) $row['encrypted_plaintext_key'],
            pointsAmount: (int) $row['points_amount'],
            status: RechargeKeyStatus::from((string) $row['status']),
            batchCode: $row['batch_code'] === null ? null : (string) $row['batch_code'],
            batchMetadata: $metadata,
            expiresAt: $this->parseDateTime($row['expires_at'] === null ? null : (string) $row['expires_at']),
            issuedByUserId: $row['issued_by_user_id'] === null ? null : (int) $row['issued_by_user_id'],
            redeemedByUserId: $row['redeemed_by_user_id'] === null ? null : (int) $row['redeemed_by_user_id'],
            redeemedLedgerEntryId: $row['redeemed_ledger_entry_id'] === null ? null : (int) $row['redeemed_ledger_entry_id'],
            redeemedAt: $this->parseDateTime($row['redeemed_at'] === null ? null : (string) $row['redeemed_at']),
        );
    }

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }
}
