<?php

declare(strict_types=1);

namespace VertoAD\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use VertoAD\Service\OAuthScopeCatalog;

final class OAuthOpenApiContractTest extends TestCase
{
    public function testOAuthClientSelfServiceContractsDeclarePermissionsAndOneTimeSecrets(): void
    {
        $openApi = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $clientPath = $this->block($openApi, '  /api/v1/oauth/clients:', '  /api/v1/oauth/clients/{client_id}/rotate-secret:');
        $rotatePath = $this->block($openApi, '  /api/v1/oauth/clients/{client_id}/rotate-secret:', '  /api/v1/webhooks/endpoints:');
        $clientSchema = $this->block($openApi, '    OAuthClient:', '    OAuthClientListData:');
        $createDataSchema = $this->block($openApi, '    OAuthClientCreateData:', '    OAuthClientSecretData:');
        $secretSchema = $this->block($openApi, '    OAuthClientSecretData:', '    OAuthAuthorizationCodeData:');

        foreach ([
            'operationId: listOAuthClients',
            'sdk.oauth_client.read.own',
            'operationId: createOAuthClient',
            'sdk.oauth_client.write.own',
            'OAuthClientCreateRequest',
            'OAuthClientCreateData',
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
        self::assertStringContainsString('omitted for public clients', $createDataSchema);
        self::assertStringNotContainsString('secret_hash', $clientSchema . $createDataSchema . $secretSchema);
    }

    public function testOAuth2FlowsAndClientSchemasEnforceScopeAndConfidentialityContract(): void
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($document);
        $oauth2 = $document['components']['securitySchemes']['OAuth2'] ?? null;
        self::assertIsArray($oauth2);
        self::assertSame('oauth2', $oauth2['type'] ?? null);

        foreach (['authorizationCode', 'clientCredentials'] as $flowName) {
            $flow = $oauth2['flows'][$flowName] ?? null;
            self::assertIsArray($flow);
            self::assertSame(OAuthScopeCatalog::all(), array_keys($flow['scopes'] ?? []));
            self::assertArrayNotHasKey('organizations.members.manage', $flow['scopes']);
            self::assertArrayNotHasKey('ops.dashboard.read.platform', $flow['scopes']);
        }

        $clientCredentials = $document['components']['schemas']['OAuthClientCredentialsTokenRequest'] ?? null;
        self::assertIsArray($clientCredentials);
        self::assertContains('client_secret', $clientCredentials['required'] ?? []);

        $authorizationCode = $document['components']['schemas']['OAuthAuthorizationCodeTokenRequest'] ?? null;
        self::assertIsArray($authorizationCode);
        self::assertArrayHasKey('client_secret', $authorizationCode['properties'] ?? []);
        self::assertNotContains('client_secret', $authorizationCode['required'] ?? []);
        self::assertStringContainsString(
            'Required only when',
            (string) ($authorizationCode['properties']['client_secret']['description'] ?? ''),
        );

        $clientCreate = $document['components']['schemas']['OAuthClientCreateRequest'] ?? null;
        self::assertIsArray($clientCreate);
        self::assertSame(OAuthScopeCatalog::all(), $clientCreate['properties']['scopes']['items']['enum'] ?? null);
        $publicConstraint = $clientCreate['allOf'][0] ?? null;
        self::assertIsArray($publicConstraint);
        self::assertFalse($publicConstraint['if']['properties']['is_confidential']['const'] ?? true);
        $publicGrantTypes = $publicConstraint['then']['properties']['grant_types']['items']['enum'] ?? [];
        self::assertSame(['authorization_code', 'refresh_token'], $publicGrantTypes);
        self::assertNotContains('client_credentials', $publicGrantTypes);

        $createData = $document['components']['schemas']['OAuthClientCreateData'] ?? null;
        self::assertIsArray($createData);
        self::assertSame(['client'], $createData['required'] ?? null);
        self::assertArrayHasKey('client_secret', $createData['properties'] ?? []);
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
