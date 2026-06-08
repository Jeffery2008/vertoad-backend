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
                'FeatureFlag:',
                'FeatureFlagEvaluation:',
                'ticket_id',
                'organization_id',
                'created_by_user_id',
                'linked_entity',
                'internal_notes',
                'percentage_rollout',
                'time_window',
                'request_id',
            ] as $contractString
        ) {
            self::assertTrue(str_contains($openApi, $contractString), $contractString . ' must be documented.');
        }
    }
}
