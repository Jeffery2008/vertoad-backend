<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class PublisherOpenApiContractTest extends TestCase
{
    public function testPublisherSiteAndSlotPathsSchemasAndEnvelopeAreDocumented(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');

        foreach (
            [
                '/api/v1/publisher/sites:',
                '/api/v1/publisher/sites/{site_id}/verification-challenge:',
                '/api/v1/publisher/sites/{site_id}/verify:',
                '/api/v1/publisher/sites/{site_id}/verification-attempts:',
                '/api/v1/publisher/ad-slot-presets:',
                '/api/v1/publisher/sites/{site_id}/slots:',
                '/api/v1/publisher/sites/{site_id}/slots/{slot_id}/integration-code:',
            ] as $path
        ) {
            self::assertTrue(str_contains($openApi, $path), $path . ' must be documented.');
        }

        foreach (
            [
                'PublisherSite:',
                'PublisherSiteVerificationChallenge:',
                'PublisherSiteVerificationAttempt:',
                'PublisherAdSlot:',
                'PublisherAdSlotIntegrationCode:',
                'PublisherAdSlotPresetMap:',
                'organization_id',
                'verification_token',
                'observed_summary',
                'failure_reason',
                'expected_value',
                'failed',
                'responsive_rules',
                'size_preset',
                'iframe_url_template',
                'hosted_script_snippet',
                'npm_install_command',
                'npm_usage_snippet',
                'viewer_id_strategy',
                'sdk_public_base_url',
                'ads_public_base_url',
            ] as $contractString
        ) {
            self::assertTrue(str_contains($openApi, $contractString), $contractString . ' must be documented.');
        }

        self::assertStringNotContainsString('observed_value', $openApi, 'Publisher verification must be server-side probed, not client-evidence based.');
        $attemptSchema = substr(
            $openApi,
            strpos($openApi, '    PublisherSiteVerificationAttempt:') ?: 0,
            (strpos($openApi, '    PublisherAdSlotResponsiveRule:') ?: strlen($openApi)) - (strpos($openApi, '    PublisherSiteVerificationAttempt:') ?: 0),
        );
        self::assertStringNotContainsString("\n        - expected_value\n", $attemptSchema, 'Attempt history must not expose reusable verification evidence.');
        self::assertStringNotContainsString("\n        expected_value:\n", $attemptSchema, 'Attempt history must not expose reusable verification evidence.');

        $integrationSchema = substr(
            $openApi,
            strpos($openApi, '    PublisherAdSlotIntegrationCode:') ?: 0,
            (strpos($openApi, '    PublisherAdSlotPreset:') ?: strlen($openApi)) - (strpos($openApi, '    PublisherAdSlotIntegrationCode:') ?: 0),
        );
        self::assertStringNotContainsString('verification_token', $integrationSchema, 'SDK integration code must not document site verification secrets.');
    }
}
