<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class BillingOpenApiContractTest extends TestCase
{
    public function testAdminRechargeKeyGenerationAndRevealContractsAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $generatePath = $this->block($openApi, '  /api/v1/billing/recharge-keys/generate:', '  /api/v1/billing/recharge-keys/{key_id}/reveal:');
        $revealPath = $this->block($openApi, '  /api/v1/billing/recharge-keys/{key_id}/reveal:', '  /api/v1/billing/withdrawals:');
        $generateSchema = $this->block($openApi, '    RechargeKeyGenerateRequest:', '    RechargeKeyBatchData:');
        $batchSchema = $this->block($openApi, '    RechargeKeyBatchData:', '    RechargeKeyAdminData:');
        $adminSchema = $this->block($openApi, '    RechargeKeyAdminData:', '    RechargeKeyRedemptionData:');

        foreach ([
            'operationId: generateRechargeKeyBatch',
            'billing.recharge_key.generate.platform',
            'RechargeKeyGenerateRequest',
            'RechargeKeyBatchData',
            'OrganizationId',
            '"201":',
            '"400":',
            '"403":',
            '"409":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $generatePath);
        }

        foreach ([
            'operationId: revealRechargeKeyPlaintext',
            'billing.recharge_key.view_plaintext.platform',
            'OrganizationId',
            'name: key_id',
            'RechargeKeyAdminData',
            '"400":',
            '"404":',
            '"403":',
            '"409":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $revealPath);
        }

        foreach (['points_amount', 'count', 'batch_code', 'batch_metadata', 'expires_at'] as $field) {
            self::assertStringContainsString($field . ':', $generateSchema . $batchSchema . $adminSchema);
        }

        self::assertStringContainsString('maximum: 500', $generateSchema);
        self::assertStringContainsString('maxItems: 500', $batchSchema);
        self::assertStringContainsString('plaintext_key:', $adminSchema);
        self::assertStringContainsString('redeemed_ledger_entry_id:', $adminSchema);
        self::assertStringNotContainsString('key_hash', $adminSchema);
    }

    public function testAdminLedgerAdjustmentAndReversalContractsAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $adjustmentPath = $this->block($openApi, '  /api/v1/billing/ledger/adjustments:', '  /api/v1/billing/ledger/{entry_id}/reversals:');
        $reversalPath = $this->block($openApi, '  /api/v1/billing/ledger/{entry_id}/reversals:', '  /api/v1/billing/recharge-keys/redeem:');
        $adjustmentSchema = $this->block($openApi, '    LedgerAdjustmentRequest:', '    LedgerReversalRequest:');
        $reversalSchema = $this->block($openApi, '    LedgerReversalRequest:', '    LedgerEntryData:');
        $entryDataSchema = $this->block($openApi, '    LedgerEntryData:', '    PointsLedgerEntry:');

        foreach ([
            'operationId: createLedgerAdjustment',
            'billing.ledger.adjust.platform',
            'OrganizationId',
            'LedgerAdjustmentRequest',
            'LedgerEntryData',
            '"200":',
            '"201":',
            '"403":',
            '"409":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $adjustmentPath);
        }

        foreach ([
            'operationId: reverseLedgerEntry',
            'billing.ledger.adjust.platform',
            'name: entry_id',
            'LedgerReversalRequest',
            'LedgerEntryData',
            '"200":',
            '"201":',
            '"404":',
            '"409":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $reversalPath);
        }

        foreach (['account_type', 'account_id', 'points_amount', 'direction', 'idempotency_key', 'reason'] as $field) {
            self::assertStringContainsString($field . ':', $adjustmentSchema);
        }

        foreach (['idempotency_key', 'reason'] as $field) {
            self::assertStringContainsString($field . ':', $reversalSchema);
        }

        self::assertStringContainsString('enum:', $adjustmentSchema);
        self::assertStringContainsString('credit', $adjustmentSchema);
        self::assertStringContainsString('debit', $adjustmentSchema);
        self::assertStringContainsString('$ref: "#/components/schemas/PointsLedgerEntry"', $entryDataSchema);
    }

    private function block(string $document, string $start, string $end): string
    {
        $startOffset = strpos($document, $start);
        self::assertNotFalse($startOffset, $start);
        $endOffset = strpos($document, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end);

        return substr($document, $startOffset, $endOffset - $startOffset);
    }
}
