<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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

    public function testPublisherWithdrawalResubmitContractIsDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $createSchema = $this->block($openApi, '    WithdrawalRequestCreate:', '    WithdrawalTransitionRequest:');
        $resubmitPath = $this->block($openApi, '  /api/v1/billing/withdrawals/{withdrawal_id}/resubmit:', '  /api/v1/billing/withdrawals/{withdrawal_id}/proofs:');
        $resubmitSchema = $this->block($openApi, '    WithdrawalResubmitRequest:', '    WithdrawalRequestData:');
        $responseSchema = $this->block($openApi, '    WithdrawalRequestData:', '    WithdrawalProofIntentData:');

        foreach ([
            'required:',
            '- idempotency_key',
            'idempotency_key:',
            'maxLength: 160',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $createSchema);
            self::assertStringContainsString($fragment, $responseSchema);
        }

        foreach ([
            'operationId: resubmitPublisherWithdrawal',
            'x-permissions:',
            '- billing.withdrawal.resubmit.own',
            'billing.withdrawal.resubmit.own',
            'OrganizationId',
            'WithdrawalId',
            'WithdrawalResubmitRequest',
            'WithdrawalRequestData',
            '"200":',
            '"401":',
            '"403":',
            '"409":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $resubmitPath);
        }

        foreach ([
            'required:',
            '- payout_account',
            'payout_account:',
            'notes:',
            'additionalProperties: false',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $resubmitSchema);
        }
    }

    public function testAdminWithdrawalQueueAndAmountSnapshotContractsAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $withdrawalsPath = $this->block($openApi, '  /api/v1/billing/withdrawals:', '  /api/v1/billing/withdrawals/{withdrawal_id}/paid:');
        $publisherOrganizationFilter = $this->block($withdrawalsPath, '        - name: publisher_organization_id', '        - name: review_status');
        $queueSchema = $this->block($openApi, '    WithdrawalQueueData:', '    WithdrawalRequestCreate:');
        $requestSchema = $this->block($openApi, '    WithdrawalRequestData:', '    WithdrawalProofCreateRequest:');

        foreach ([
            'OrganizationId',
            'get:',
            'operationId: listWithdrawalQueue',
            'x-permissions:',
            '- billing.withdrawal.read.platform',
            'name: publisher_organization_id',
            'name: review_status',
            'name: payment_status',
            'name: limit',
            'WithdrawalQueueData',
            '"200":',
            '"400":',
            '"401":',
            '"403":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $withdrawalsPath);
        }

        foreach ([
            'in: query',
            'required: false',
            'Optional publisher organization filter',
            '`organization_id` query parameter remains the platform permission scope',
            'minimum: 1',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $publisherOrganizationFilter);
        }

        foreach ([
            'post:',
            'operationId: requestPublisherWithdrawal',
            'WithdrawalRequestCreate',
            'WithdrawalRequestData',
            '"201":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $withdrawalsPath);
        }

        foreach ([
            'required:',
            '- withdrawals',
            '- limit',
            '- review_status',
            '- payment_status',
            'withdrawals:',
            '$ref: "#/components/schemas/WithdrawalRequestData"',
            'limit:',
            'maximum: 200',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $queueSchema);
        }

        foreach ([
            '- amount_cny',
            '- points_per_cny',
            '- currency',
            'points_amount:',
            'amount_cny:',
            'pattern: "^[0-9]+\\\\.[0-9]{2}$"',
            'points_per_cny:',
            'const: 100',
            'currency:',
            'const: CNY',
            'requested_at:',
            'paid_at:',
            '100 points equals 1 CNY',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $requestSchema);
        }
    }

    public function testWithdrawalQueueAndOwnHistoryUseIndependentReviewAndPaymentFilters(): void
    {
        $openApi = $this->parsedOpenApi();
        $queue = $this->operation($openApi, '/api/v1/billing/withdrawals', 'get');

        self::assertSame('listWithdrawalQueue', $queue['operationId'] ?? null);
        $this->assertBearerPermission($queue, 'billing.withdrawal.read.platform');
        $this->assertParameters(
            $queue,
            ['#/components/parameters/OrganizationId'],
            ['publisher_organization_id', 'review_status', 'payment_status', 'limit'],
        );
        self::assertSame(
            ['pending', 'approved', 'rejected', 'revoked', 'all'],
            $this->parameterSchema($queue, 'review_status')['enum'] ?? null,
        );
        self::assertSame(
            ['not_started', 'pending', 'paid', 'all'],
            $this->parameterSchema($queue, 'payment_status')['enum'] ?? null,
        );
        self::assertArrayNotHasKey('status', $this->namedParameters($queue));
        $this->assertSuccessDataSchema($queue, 200, 'WithdrawalQueueData');
        $this->assertResponseStatuses($queue, [200, 400, 401, 403, 422, 'default']);

        $own = $this->operation($openApi, '/api/v1/billing/withdrawals/own', 'get');
        self::assertSame('listOwnPublisherWithdrawals', $own['operationId'] ?? null);
        $this->assertBearerPermission($own, 'billing.withdrawal.read.own');
        $this->assertParameters(
            $own,
            ['#/components/parameters/OrganizationId'],
            ['review_status', 'payment_status', 'limit'],
        );
        self::assertSame(
            ['pending', 'approved', 'rejected', 'revoked', 'all'],
            $this->parameterSchema($own, 'review_status')['enum'] ?? null,
        );
        self::assertSame(
            ['not_started', 'pending', 'paid', 'all'],
            $this->parameterSchema($own, 'payment_status')['enum'] ?? null,
        );
        $this->assertSuccessDataSchema($own, 200, 'WithdrawalQueueData');
        $this->assertResponseStatuses($own, [200, 400, 401, 403, 422, 'default']);

        $create = $this->operation($openApi, '/api/v1/billing/withdrawals', 'post');
        self::assertSame('requestPublisherWithdrawal', $create['operationId'] ?? null);
        $this->assertBearerPermission($create, 'billing.withdrawal.request.own');
        $this->assertParameters($create, ['#/components/parameters/OrganizationId'], []);
        $this->assertRequestSchema($create, true, 'WithdrawalRequestCreate');
        $this->assertSuccessDataSchema($create, 201, 'WithdrawalRequestData');
        $this->assertResponseStatuses($create, [201, 400, 401, 403, 409, 422, 'default']);

        $createSchema = $this->schema($openApi, 'WithdrawalRequestCreate');
        self::assertSame(
            ['points_amount', 'payout_method', 'payout_account', 'idempotency_key'],
            $createSchema['required'] ?? null,
        );
        self::assertSame(
            ['idempotency_key', 'points_amount', 'payout_method', 'payout_account', 'notes'],
            array_keys($createSchema['properties'] ?? []),
        );
        self::assertFalse($createSchema['additionalProperties'] ?? true);
    }

    public function testWithdrawalLifecycleOperationsDocumentReviewBeforeVerifiedPayment(): void
    {
        $openApi = $this->parsedOpenApi();
        $operations = [
            '/api/v1/billing/withdrawals/{withdrawal_id}/approve' => [
                'approvePublisherWithdrawal',
                'billing.withdrawal.review.platform',
                false,
                'WithdrawalTransitionRequest',
            ],
            '/api/v1/billing/withdrawals/{withdrawal_id}/reject' => [
                'rejectPublisherWithdrawal',
                'billing.withdrawal.review.platform',
                true,
                'WithdrawalReviewRequest',
            ],
            '/api/v1/billing/withdrawals/{withdrawal_id}/revoke' => [
                'revokePublisherWithdrawal',
                'billing.withdrawal.revoke.own',
                false,
                'WithdrawalTransitionRequest',
            ],
            '/api/v1/billing/withdrawals/{withdrawal_id}/resubmit' => [
                'resubmitPublisherWithdrawal',
                'billing.withdrawal.resubmit.own',
                true,
                'WithdrawalResubmitRequest',
            ],
            '/api/v1/billing/withdrawals/{withdrawal_id}/paid' => [
                'markPublisherWithdrawalPaid',
                'billing.withdrawal.mark_paid.platform',
                true,
                'WithdrawalPaidRequest',
            ],
        ];

        foreach ($operations as $path => [$operationId, $permission, $bodyRequired, $requestSchema]) {
            $operation = $this->operation($openApi, $path, 'post');
            self::assertSame($operationId, $operation['operationId'] ?? null, $path);
            $this->assertBearerPermission($operation, $permission);
            $this->assertParameters(
                $operation,
                ['#/components/parameters/OrganizationId', '#/components/parameters/WithdrawalId'],
                [],
            );
            $this->assertRequestSchema($operation, $bodyRequired, $requestSchema);
            $this->assertSuccessDataSchema($operation, 200, 'WithdrawalRequestData');
            $this->assertResponseStatuses($operation, [200, 400, 401, 403, 404, 409, 422, 'default']);
        }

        $review = $this->schema($openApi, 'WithdrawalReviewRequest');
        self::assertSame(['notes'], $review['required'] ?? null);
        self::assertSame(['notes'], array_keys($review['properties'] ?? []));
        self::assertSame(1, $review['properties']['notes']['minLength'] ?? null);
        self::assertSame(2000, $review['properties']['notes']['maxLength'] ?? null);
        self::assertSame('\\S', $review['properties']['notes']['pattern'] ?? null);
        self::assertFalse($review['additionalProperties'] ?? true);

        $paid = $this->schema($openApi, 'WithdrawalPaidRequest');
        self::assertSame(['proof_id', 'notes'], $paid['required'] ?? null);
        self::assertSame(['proof_id', 'notes'], array_keys($paid['properties'] ?? []));
        self::assertSame(1, $paid['properties']['proof_id']['minimum'] ?? null);
        self::assertSame(1, $paid['properties']['notes']['minLength'] ?? null);
        self::assertSame(2000, $paid['properties']['notes']['maxLength'] ?? null);
        self::assertSame('\\S', $paid['properties']['notes']['pattern'] ?? null);
        self::assertFalse($paid['additionalProperties'] ?? true);

        $resubmit = $this->schema($openApi, 'WithdrawalResubmitRequest');
        self::assertSame(['payout_account'], $resubmit['required'] ?? null);
        self::assertSame(1, $resubmit['properties']['payout_account']['minProperties'] ?? null);
    }

    public function testWithdrawalResponseSchemaMatchesTheSeparatedSerializerFieldsExactly(): void
    {
        $openApi = $this->parsedOpenApi();
        $request = $this->schema($openApi, 'WithdrawalRequestData');
        $serializedFields = [
            'id',
            'organization_id',
            'requested_by_user_id',
            'points_amount',
            'amount_cny',
            'points_per_cny',
            'currency',
            'idempotency_key',
            'review_status',
            'payment_status',
            'payout_method',
            'payout_account',
            'applicant_notes',
            'reviewer_user_id',
            'reviewer_notes',
            'payment_proof_id',
            'payment_completed_by_user_id',
            'payment_notes',
            'ledger_entry_id',
            'requested_at',
            'reviewed_at',
            'approved_at',
            'paid_at',
            'rejected_at',
            'revoked_at',
            'resubmitted_at',
        ];

        self::assertSame($serializedFields, $request['required'] ?? null);
        self::assertSame($serializedFields, array_keys($request['properties'] ?? []));
        self::assertArrayNotHasKey('status', $request['properties']);
        self::assertSame(
            ['pending', 'approved', 'rejected', 'revoked'],
            $request['properties']['review_status']['enum'] ?? null,
        );
        self::assertSame(
            ['not_started', 'pending', 'paid'],
            $request['properties']['payment_status']['enum'] ?? null,
        );
        self::assertSame(['integer', 'null'], $request['properties']['payment_proof_id']['type'] ?? null);
        self::assertSame(['integer', 'null'], $request['properties']['payment_completed_by_user_id']['type'] ?? null);
        self::assertSame(['string', 'null'], $request['properties']['payment_notes']['type'] ?? null);
        self::assertSame(['string', 'null'], $request['properties']['approved_at']['type'] ?? null);
        self::assertSame(100, $request['properties']['points_per_cny']['const'] ?? null);
        self::assertSame('CNY', $request['properties']['currency']['const'] ?? null);
        self::assertFalse($request['additionalProperties'] ?? true);

        $queue = $this->schema($openApi, 'WithdrawalQueueData');
        self::assertSame(
            ['withdrawals', 'limit', 'review_status', 'payment_status'],
            $queue['required'] ?? null,
        );
        self::assertArrayNotHasKey('status', $queue['properties']);
        self::assertSame(
            ['pending', 'approved', 'rejected', 'revoked', null],
            $queue['properties']['review_status']['enum'] ?? null,
        );
        self::assertSame(
            ['not_started', 'pending', 'paid', null],
            $queue['properties']['payment_status']['enum'] ?? null,
        );
    }

    public function testWithdrawalProofContractIsPlatformOwnedAndServerVerified(): void
    {
        $openApi = $this->parsedOpenApi();
        $list = $this->operation($openApi, '/api/v1/billing/withdrawals/{withdrawal_id}/proofs', 'get');
        self::assertSame('listWithdrawalPaymentProofs', $list['operationId'] ?? null);
        $this->assertBearerPermission($list, 'billing.withdrawal.payment_proof.platform');
        $this->assertParameters(
            $list,
            ['#/components/parameters/OrganizationId', '#/components/parameters/WithdrawalId'],
            ['limit'],
        );
        $this->assertSuccessDataSchema($list, 200, 'WithdrawalProofListData');
        $this->assertResponseStatuses($list, [200, 400, 401, 403, 404, 422, 'default']);
        $listParameters = $this->namedParameters($list);
        self::assertSame(1, $listParameters['limit']['schema']['minimum'] ?? null);
        self::assertSame(100, $listParameters['limit']['schema']['maximum'] ?? null);
        self::assertSame(50, $listParameters['limit']['schema']['default'] ?? null);

        $create = $this->operation($openApi, '/api/v1/billing/withdrawals/{withdrawal_id}/proofs', 'post');
        self::assertSame('createWithdrawalProofUploadIntent', $create['operationId'] ?? null);
        $this->assertBearerPermission($create, 'billing.withdrawal.payment_proof.platform');
        $this->assertParameters(
            $create,
            ['#/components/parameters/OrganizationId', '#/components/parameters/WithdrawalId'],
            [],
        );
        $this->assertRequestSchema($create, true, 'WithdrawalProofCreateRequest');
        $this->assertSuccessDataSchema($create, 201, 'WithdrawalProofIntentData');
        $this->assertResponseStatuses($create, [201, 400, 401, 403, 404, 409, 422, 'default']);

        $confirm = $this->operation($openApi, '/api/v1/billing/withdrawals/{withdrawal_id}/proofs/confirm', 'post');
        self::assertSame('confirmWithdrawalProofUpload', $confirm['operationId'] ?? null);
        $this->assertBearerPermission($confirm, 'billing.withdrawal.payment_proof.platform');
        $this->assertParameters(
            $confirm,
            ['#/components/parameters/OrganizationId', '#/components/parameters/WithdrawalId'],
            [],
        );
        $this->assertRequestSchema($confirm, true, 'WithdrawalProofConfirmRequest');
        $this->assertSuccessDataSchema($confirm, 200, 'WithdrawalProofData');
        $this->assertResponseStatuses($confirm, [200, 400, 401, 403, 404, 409, 422, 503, 'default']);
        self::assertStringContainsString('server', strtolower((string) ($confirm['description'] ?? '')));
        self::assertStringContainsString('not accepted', strtolower((string) ($confirm['description'] ?? '')));

        $createSchema = $this->schema($openApi, 'WithdrawalProofCreateRequest');
        self::assertSame(['filename', 'content_type', 'byte_size'], $createSchema['required'] ?? null);
        self::assertSame(['filename', 'content_type', 'byte_size'], array_keys($createSchema['properties'] ?? []));
        self::assertSame(
            ['application/pdf', 'image/jpeg', 'image/png'],
            $createSchema['properties']['content_type']['enum'] ?? null,
        );
        self::assertSame(10_485_760, $createSchema['properties']['byte_size']['maximum'] ?? null);

        $confirmSchema = $this->schema($openApi, 'WithdrawalProofConfirmRequest');
        self::assertSame(['proof_id'], $confirmSchema['required'] ?? null);
        self::assertSame(['proof_id'], array_keys($confirmSchema['properties'] ?? []));
        foreach (['object_key', 'content_type', 'byte_size', 'checksum'] as $untrustedField) {
            self::assertArrayNotHasKey($untrustedField, $confirmSchema['properties']);
        }
        self::assertFalse($confirmSchema['additionalProperties'] ?? true);

        $proof = $this->schema($openApi, 'WithdrawalProofData');
        $proofFields = [
            'id',
            'withdrawal_request_id',
            'organization_id',
            'uploaded_by_user_id',
            'object_key',
            'content_type',
            'byte_size',
            'checksum',
            'status',
            'verification_error_code',
            'created_at',
            'verification_attempted_at',
            'verified_at',
        ];
        self::assertSame($proofFields, $proof['required'] ?? null);
        self::assertSame($proofFields, array_keys($proof['properties'] ?? []));
        self::assertSame(
            ['pending_upload', 'verified', 'rejected'],
            $proof['properties']['status']['enum'] ?? null,
        );
        self::assertSame('^sha256:[a-f0-9]{64}$', $proof['properties']['checksum']['pattern'] ?? null);
        self::assertSame(['string', 'null'], $proof['properties']['verification_error_code']['type'] ?? null);
        self::assertSame(['string', 'null'], $proof['properties']['verification_attempted_at']['type'] ?? null);
        self::assertSame(['string', 'null'], $proof['properties']['verified_at']['type'] ?? null);
        self::assertFalse($proof['additionalProperties'] ?? true);

        $proofList = $this->schema($openApi, 'WithdrawalProofListData');
        self::assertSame(['withdrawal_request_id', 'proofs', 'limit'], $proofList['required'] ?? null);
        self::assertSame(['withdrawal_request_id', 'proofs', 'limit'], array_keys($proofList['properties'] ?? []));
        self::assertSame(
            '#/components/schemas/WithdrawalProofData',
            $proofList['properties']['proofs']['items']['$ref'] ?? null,
        );

        $upload = $this->schema($openApi, 'WithdrawalProofUploadData');
        self::assertSame(['url', 'method', 'object_key', 'status_code', 'headers'], $upload['required'] ?? null);
        self::assertSame(['url', 'method', 'object_key', 'status_code', 'headers'], array_keys($upload['properties'] ?? []));
        self::assertSame('PUT', $upload['properties']['method']['const'] ?? null);

        $intent = $this->schema($openApi, 'WithdrawalProofIntentData');
        self::assertSame(
            '#/components/schemas/WithdrawalProofData',
            $intent['properties']['proof']['$ref'] ?? null,
        );
        self::assertSame(
            '#/components/schemas/WithdrawalProofUploadData',
            $intent['properties']['upload']['$ref'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedOpenApi(): array
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($openApi);

        return $openApi;
    }

    /**
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function operation(array $openApi, string $path, string $method): array
    {
        $operation = $openApi['paths'][$path][$method] ?? null;
        self::assertIsArray($operation, strtoupper($method) . ' ' . $path);

        return $operation;
    }

    /**
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function schema(array $openApi, string $name): array
    {
        $schema = $openApi['components']['schemas'][$name] ?? null;
        self::assertIsArray($schema, $name);

        return $schema;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function assertBearerPermission(array $operation, string $permission): void
    {
        self::assertSame([['BearerAuth' => []]], $operation['security'] ?? null);
        self::assertSame([$permission], $operation['x-permissions'] ?? null);
    }

    /**
     * @param array<string, mixed> $operation
     * @param list<string> $expectedRefs
     * @param list<string> $expectedNames
     */
    private function assertParameters(array $operation, array $expectedRefs, array $expectedNames): void
    {
        $parameters = $operation['parameters'] ?? null;
        self::assertIsArray($parameters);

        $refs = [];
        $names = [];
        foreach ($parameters as $parameter) {
            self::assertIsArray($parameter);
            if (isset($parameter['$ref'])) {
                $refs[] = $parameter['$ref'];
                continue;
            }

            $names[] = $parameter['name'] ?? null;
        }

        self::assertSame($expectedRefs, $refs);
        self::assertSame($expectedNames, $names);
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, array<string, mixed>>
     */
    private function namedParameters(array $operation): array
    {
        $named = [];
        foreach (($operation['parameters'] ?? []) as $parameter) {
            if (is_array($parameter) && isset($parameter['name']) && is_string($parameter['name'])) {
                $named[$parameter['name']] = $parameter;
            }
        }

        return $named;
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function parameterSchema(array $operation, string $name): array
    {
        $parameter = $this->namedParameters($operation)[$name] ?? null;
        self::assertIsArray($parameter, $name);
        $schema = $parameter['schema'] ?? null;
        self::assertIsArray($schema, $name);

        return $schema;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function assertRequestSchema(
        array $operation,
        bool $required,
        string $schemaName,
    ): void {
        self::assertSame($required, $operation['requestBody']['required'] ?? null);
        self::assertSame(
            '#/components/schemas/' . $schemaName,
            $operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function assertSuccessDataSchema(array $operation, int $status, string $schemaName): void
    {
        $response = $operation['responses'][$status] ?? null;
        self::assertIsArray($response, (string) $status);
        $allOf = $response['content']['application/json']['schema']['allOf'] ?? null;
        self::assertIsArray($allOf, (string) $status);
        self::assertSame('#/components/schemas/SuccessEnvelope', $allOf[0]['$ref'] ?? null);
        self::assertSame(
            '#/components/schemas/' . $schemaName,
            $allOf[1]['properties']['data']['$ref'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $operation
     * @param list<int|string> $expected
     */
    private function assertResponseStatuses(array $operation, array $expected): void
    {
        $responses = $operation['responses'] ?? null;
        self::assertIsArray($responses);
        self::assertSame(
            array_map(static fn (int|string $status): string => (string) $status, $expected),
            array_map(static fn (int|string $status): string => (string) $status, array_keys($responses)),
        );

        foreach ($expected as $status) {
            if ((string) $status === 'default' || (int) $status >= 400) {
                self::assertSame(
                    '#/components/responses/Error',
                    $responses[$status]['$ref'] ?? null,
                    (string) $status,
                );
            }
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
