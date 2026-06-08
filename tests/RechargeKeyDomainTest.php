<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Recharge\RechargeKey;
use VertoAD\Domain\Recharge\RechargeKeyStatus;

final class RechargeKeyDomainTest extends TestCase
{
    public function testConstructorRejectsInvalidIdentityHashPlaintextAndPointValues(): void
    {
        $invalidCases = [
            'organization ID' => [
                'overrides' => ['organizationId' => 0],
                'message' => 'Recharge key organization ID must be positive when provided.',
            ],
            'key hash' => [
                'overrides' => ['keyHash' => str_repeat('g', 64)],
                'message' => 'Recharge key hash must be a SHA-256 hex digest.',
            ],
            'encrypted plaintext' => [
                'overrides' => ['encryptedPlaintextKey' => ''],
                'message' => 'Recharge key encrypted plaintext is required.',
            ],
            'points amount' => [
                'overrides' => ['pointsAmount' => 0],
                'message' => 'Recharge key points amount must be positive.',
            ],
            'issuer user ID' => [
                'overrides' => ['issuedByUserId' => 0],
                'message' => 'Recharge key issuer user ID must be positive when provided.',
            ],
            'redeemer user ID' => [
                'overrides' => ['redeemedByUserId' => 0],
                'message' => 'Recharge key redeemer user ID must be positive when provided.',
            ],
            'ledger entry ID' => [
                'overrides' => ['redeemedLedgerEntryId' => 0],
                'message' => 'Recharge key ledger entry ID must be positive when provided.',
            ],
        ];

        foreach ($invalidCases as $case) {
            try {
                $this->makeRechargeKey($case['overrides']);
                self::fail('Expected invalid recharge key to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame($case['message'], $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeRechargeKey(array $overrides = []): RechargeKey
    {
        $defaults = [
            'id' => 1,
            'organizationId' => 10,
            'keyHash' => hash('sha256', 'rk_live_ABC123'),
            'encryptedPlaintextKey' => 'encrypted:rk_live_ABC123',
            'pointsAmount' => 500,
            'status' => RechargeKeyStatus::Issued,
            'batchCode' => 'batch-1',
            'batchMetadata' => ['source' => 'manual'],
            'expiresAt' => new DateTimeImmutable('2026-06-30 00:00:00+00:00'),
            'issuedByUserId' => 7,
            'redeemedByUserId' => null,
            'redeemedLedgerEntryId' => null,
            'redeemedAt' => null,
        ];
        $data = [...$defaults, ...$overrides];

        return new RechargeKey(...$data);
    }
}
