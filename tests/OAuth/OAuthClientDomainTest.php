<?php

declare(strict_types=1);

namespace VertoAD\Tests\OAuth;

use PHPUnit\Framework\TestCase;
use VertoAD\Domain\Auth\OAuthClient;

final class OAuthClientDomainTest extends TestCase
{
    public function testNormalizesRedirectUrisGrantTypesAndScopes(): void
    {
        $client = new OAuthClient(
            id: null,
            organizationId: 10,
            ownerUserId: 20,
            clientIdentifier: 'client_123',
            name: '  Developer App  ',
            secretHash: 'hashed-secret',
            redirectUris: [' https://app.example.com/callback ', 'http://localhost:5173/callback', 'https://app.example.com/callback'],
            grantTypes: ['authorization_code', 'client_credentials', 'authorization_code'],
            scopes: ['reports.read', 'campaigns.manage', 'reports.read'],
            isConfidential: true,
            revokedAt: null,
        );

        self::assertSame('Developer App', $client->name);
        self::assertSame(['https://app.example.com/callback', 'http://localhost:5173/callback'], $client->redirectUris);
        self::assertSame(['authorization_code', 'client_credentials'], $client->grantTypes);
        self::assertSame(['campaigns.manage', 'reports.read'], $client->scopes);
        self::assertFalse($client->isRevoked());
    }

    public function testRejectsUnsafeRedirectUrisAndInvalidScopes(): void
    {
        foreach ([
            'OAuth client ID must be positive when provided.' => ['id' => 0, 'redirects' => ['https://app.example.com/callback'], 'scopes' => ['reports.read']],
            'OAuth client organization ID must be positive when provided.' => ['organizationId' => 0, 'redirects' => ['https://app.example.com/callback'], 'scopes' => ['reports.read']],
            'OAuth client owner user ID must be positive when provided.' => ['ownerUserId' => 0, 'redirects' => ['https://app.example.com/callback'], 'scopes' => ['reports.read']],
            'OAuth client identifier is required.' => ['clientIdentifier' => ' ', 'redirects' => ['https://app.example.com/callback'], 'scopes' => ['reports.read']],
            'OAuth client secret hash must not be blank when provided.' => ['secretHash' => ' ', 'redirects' => ['https://app.example.com/callback'], 'scopes' => ['reports.read']],
            'OAuth client requires at least one redirect URI.' => ['redirects' => [' '], 'scopes' => ['reports.read']],
            'OAuth client requires at least one grant type.' => ['redirects' => ['https://app.example.com/callback'], 'grantTypes' => [' '], 'scopes' => ['reports.read']],
            'OAuth client grant type is not supported.' => ['redirects' => ['https://app.example.com/callback'], 'grantTypes' => ['password'], 'scopes' => ['reports.read']],
            'OAuth client redirect URI must be a valid URL.' => ['redirects' => ['not a url'], 'scopes' => ['reports.read']],
            'OAuth client redirect URI must use HTTPS except localhost development URLs.' => ['redirects' => ['http://evil.example/callback'], 'scopes' => ['reports.read']],
            'OAuth client scope must use dot-separated permission code format.' => ['redirects' => ['https://app.example.com/callback'], 'scopes' => ['bad-scope']],
            'OAuth client name is required.' => ['name' => ' ', 'redirects' => ['https://app.example.com/callback'], 'scopes' => ['reports.read']],
        ] as $message => $input) {
            try {
                new OAuthClient(
                    id: $input['id'] ?? null,
                    organizationId: $input['organizationId'] ?? 10,
                    ownerUserId: $input['ownerUserId'] ?? 20,
                    clientIdentifier: $input['clientIdentifier'] ?? 'client_123',
                    name: $input['name'] ?? 'Developer App',
                    secretHash: $input['secretHash'] ?? 'hashed-secret',
                    redirectUris: $input['redirects'],
                    grantTypes: $input['grantTypes'] ?? ['authorization_code'],
                    scopes: $input['scopes'],
                    isConfidential: true,
                    revokedAt: null,
                );
                self::fail('Expected invalid OAuth client input to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }
}
