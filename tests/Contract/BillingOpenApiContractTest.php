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

    private function block(string $document, string $start, string $end): string
    {
        $startOffset = strpos($document, $start);
        self::assertNotFalse($startOffset, $start);
        $endOffset = strpos($document, $end, $startOffset + strlen($start));
        self::assertNotFalse($endOffset, $end);

        return substr($document, $startOffset, $endOffset - $startOffset);
    }
}
