<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class SupportFeatureFlagsOpenApiContractTest extends TestCase
{
    public function testTask29SupportAndFeatureFlagPathsSchemasAndEnvelopeAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');

        foreach (
            [
                '/api/v1/support/tickets:',
                '/api/v1/support/tickets/{ticket_id}/notes:',
                '/api/v1/support/tickets/{ticket_id}/status:',
                '/api/v1/feature-flags:',
                '/api/v1/feature-flags/{flag_key}/evaluate:',
            ] as $path
        ) {
            self::assertTrue(str_contains($openApi, $path), $path . ' must be documented.');
        }

        foreach (
            [
                'SupportTicket:',
                'SupportTicketNote:',
                'FeatureFlagCreateRequest:',
                'FeatureFlag:',
                'FeatureFlagEvaluation:',
                'ticket_id',
                'organization_id',
                'created_by_user_id',
                'internal:',
                'linked_entity',
                'internal_notes',
                'name: environment',
                'percentage_rollout',
                'time_window',
                'request_id',
            ] as $contractString
        ) {
            self::assertTrue(str_contains($openApi, $contractString), $contractString . ' must be documented.');
        }
    }

    public function testSupportTicketListOrganizationIdQueryParameterIsDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $operation = $this->operationBlock($openApi, 'operationId: listSupportTickets', '  /api/v1/support/tickets/{ticket_id}/notes:');

        self::assertStringContainsString('parameters:', $operation);
        self::assertStringContainsString('- name: organization_id', $operation);
        self::assertStringContainsString('in: query', $operation);
        self::assertStringContainsString('minimum: 1', $operation);
        self::assertStringContainsString('"422":', $operation);
    }

    private function operationBlock(string $openApi, string $start, string $end): string
    {
        $startOffset = strpos($openApi, $start);
        self::assertNotFalse($startOffset, $start . ' must be documented.');

        $endOffset = strpos($openApi, $end, $startOffset);
        self::assertNotFalse($endOffset, $end . ' must follow ' . $start . '.');

        return substr($openApi, $startOffset, $endOffset - $startOffset);
    }
}
