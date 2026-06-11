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
            'x-permissions:',
            '- billing.ledger.adjust.platform',
            'billing.ledger.adjust.platform',
            'OrganizationId',
            'LedgerAdjustmentRequest',
            'LedgerEntryData',
            '"200":',
            '"201":',
            '"400":',
            '"401":',
            '"403":',
            '"409":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $adjustmentPath);
        }

        foreach ([
            'operationId: reverseLedgerEntry',
            'x-permissions:',
            '- billing.ledger.adjust.platform',
            'billing.ledger.adjust.platform',
            'name: entry_id',
            'LedgerReversalRequest',
            'LedgerEntryData',
            '"200":',
            '"201":',
            '"400":',
            '"401":',
            '"403":',
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
        self::assertStringContainsString('advertiser_balance', $adjustmentSchema);
        self::assertStringContainsString('publisher_earnings', $adjustmentSchema);
        self::assertStringContainsString('maxLength: 160', $adjustmentSchema);
        self::assertStringContainsString('maxLength: 240', $adjustmentSchema);
        self::assertStringContainsString('maxLength: 160', $reversalSchema);
        self::assertStringContainsString('maxLength: 240', $reversalSchema);
        self::assertStringContainsString('$ref: "#/components/schemas/PointsLedgerEntry"', $entryDataSchema);

        foreach (['200', '201'] as $status) {
            self::assertStringContainsString('LedgerEntryData', $this->responseBlock($adjustmentPath, $status));
            self::assertStringContainsString('LedgerEntryData', $this->responseBlock($reversalPath, $status));
        }

        foreach (['400', '401', '403', '409', '422'] as $status) {
            self::assertStringContainsString('#/components/responses/Error', $this->responseBlock($adjustmentPath, $status));
        }

        foreach (['400', '401', '403', '404', '409', '422'] as $status) {
            self::assertStringContainsString('#/components/responses/Error', $this->responseBlock($reversalPath, $status));
        }
    }

    private function block(string $document, string $start, string $end): string
    {
        $startOffset = strpos($document, $start);
        self::assertNotFalse($startOffset, $start);
        $endOffset = strpos($document, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end);

        return substr($document, $startOffset, $endOffset - $startOffset);
    }

    private function responseBlock(string $operationBlock, string $status): string
    {
        $lines = preg_split('/\R/', $operationBlock);
        self::assertIsArray($lines);

        $capturing = false;
        $block = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s{8}"?' . preg_quote($status, '/') . '"?:\s*$/', $line) === 1) {
                $capturing = true;
                $block[] = $line;
                continue;
            }

            if ($capturing && preg_match('/^\s{8}(?:"?\d{3}"?|default):\s*$/', $line) === 1) {
                break;
            }

            if ($capturing) {
                $block[] = $line;
            }
        }

        self::assertNotSame([], $block, $status);

        return implode(PHP_EOL, $block);
    }
}
