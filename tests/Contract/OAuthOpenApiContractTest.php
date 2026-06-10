<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class OAuthOpenApiContractTest extends TestCase
{
    public function testOAuthClientSelfServiceContractsDeclarePermissionsAndOneTimeSecrets(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $clientPath = $this->block($openApi, '  /api/v1/oauth/clients:', '  /api/v1/oauth/clients/{client_id}/rotate-secret:');
        $rotatePath = $this->block($openApi, '  /api/v1/oauth/clients/{client_id}/rotate-secret:', '  /api/v1/webhooks/endpoints:');
        $clientSchema = $this->block($openApi, '    OAuthClient:', '    OAuthClientListData:');
        $secretSchema = $this->block($openApi, '    OAuthClientSecretData:', '    OAuthAuthorizationCodeData:');

        foreach ([
            'operationId: listOAuthClients',
            'sdk.oauth_client.read.own',
            'operationId: createOAuthClient',
            'sdk.oauth_client.write.own',
            'OAuthClientCreateRequest',
            'OAuthClientSecretData',
            'secret exactly once',
            '"201":',
            '"403":',
            '"422":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $clientPath);
        }

        foreach ([
            'operationId: rotateOAuthClientSecret',
            'sdk.oauth_client.rotate_secret.own',
            'name: client_id',
            'OAuthClientSecretData',
            'secret exactly once',
            '"404":',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $rotatePath);
        }

        self::assertStringContainsString('client_secret:', $secretSchema);
        self::assertStringContainsString('Plaintext client secret returned only on create or rotate.', $secretSchema);
        self::assertStringNotContainsString('secret_hash', $clientSchema . $secretSchema);
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
