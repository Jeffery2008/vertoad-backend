<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Auth\OAuthClient;
use VertoAD\Repository\OAuthClientRepositoryInterface;
use VertoAD\Repository\OAuthConsentRepositoryInterface;
use VertoAD\Repository\OAuthTokenRepositoryInterface;

final readonly class OAuthTokenService
{
    /** @param (callable(): string)|null $tokenFactory */
    public function __construct(
        private OAuthClientRepositoryInterface $clients,
        private OAuthTokenRepositoryInterface $tokens,
        private OAuthConsentRepositoryInterface $consents,
        private OAuthClientSecretHasher $secrets,
        private mixed $tokenFactory = null,
        private int $authorizationCodeTtlSeconds = 300,
        private int $accessTokenTtlSeconds = 900,
        private int $refreshTokenTtlSeconds = 2592000,
    ) {
    }

    /** @param list<string> $scopes */
    public function authorize(
        int $userId,
        ?int $organizationId,
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        DateTimeImmutable $now,
    ): array {
        $client = $this->requireClient($clientId, 'authorization_code');
        if (!in_array($redirectUri, $client->redirectUris, true)) {
            throw new InvalidArgumentException('OAuth redirect URI does not match the registered client.');
        }

        $codeChallenge = trim($codeChallenge);
        $codeChallengeMethod = trim($codeChallengeMethod);
        if ($codeChallenge === '' || $codeChallengeMethod !== 'S256') {
            throw new InvalidArgumentException('OAuth authorization code flow requires S256 PKCE.');
        }

        $scopes = $this->constrainScopes($client, $scopes);
        if (!$this->consents->hasConsentFor($client, $userId, $organizationId, $scopes)) {
            throw new RuntimeException('OAuth authorization requires user consent for the requested scopes.');
        }

        $plainCode = $this->plainToken('voac_');
        $codeId = $this->tokens->createAuthorizationCode(
            $client,
            $userId,
            $organizationId,
            hash('sha256', $plainCode),
            $redirectUri,
            $scopes,
            $codeChallenge,
            $codeChallengeMethod,
            $now->add(new DateInterval('PT' . $this->authorizationCodeTtlSeconds . 'S')),
        );

        return ['code' => $plainCode, 'code_id' => $codeId, 'expires_in' => $this->authorizationCodeTtlSeconds];
    }

    /** @param list<string> $scopes */
    public function consent(int $userId, ?int $organizationId, string $clientId, array $scopes, DateTimeImmutable $now): array
    {
        $client = $this->requireClient($clientId, 'authorization_code');
        $scopes = $this->constrainScopes($client, $scopes);
        $this->consents->grantConsent($client, $userId, $organizationId, $scopes, $now);

        return [
            'client' => [
                'client_id' => $client->clientIdentifier,
                'name' => $client->name,
            ],
            'scopes' => $scopes,
            'granted_at' => $now->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $input */
    public function token(array $input, DateTimeImmutable $now): array
    {
        $grantType = trim((string) ($input['grant_type'] ?? ''));

        return match ($grantType) {
            'authorization_code' => $this->authorizationCodeToken($input, $now),
            'client_credentials' => $this->clientCredentialsToken($input, $now),
            'refresh_token' => $this->refreshToken($input, $now),
            default => throw new InvalidArgumentException('OAuth grant type is unsupported.'),
        };
    }

    public function revoke(string $token, ?string $tokenTypeHint, DateTimeImmutable $now): array
    {
        $hash = hash('sha256', trim($token));
        $hint = $tokenTypeHint === null ? null : trim($tokenTypeHint);
        $revoked = false;

        if ($hint === null || $hint === 'access_token') {
            $revoked = $this->tokens->revokeAccessToken($hash, $now) || $revoked;
        }

        if ($hint === null || $hint === 'refresh_token') {
            $revoked = $this->tokens->revokeRefreshToken($hash, $now) || $revoked;
        }

        return ['revoked' => $revoked];
    }

    /** @param array<string, mixed> $input */
    private function authorizationCodeToken(array $input, DateTimeImmutable $now): array
    {
        $code = trim((string) ($input['code'] ?? ''));
        $verifier = trim((string) ($input['code_verifier'] ?? ''));
        $redirectUri = trim((string) ($input['redirect_uri'] ?? ''));
        if ($code === '' || $verifier === '' || $redirectUri === '') {
            throw new InvalidArgumentException('Authorization code, verifier, and redirect URI are required.');
        }

        $grant = $this->tokens->consumeAuthorizationCode(hash('sha256', $code), $now);
        if ($grant === null) {
            throw new RuntimeException('OAuth authorization code is invalid, expired, or already used.');
        }

        if (!hash_equals((string) $grant['redirect_uri'], $redirectUri)) {
            throw new RuntimeException('OAuth redirect URI does not match the authorization request.');
        }

        if (!hash_equals((string) $grant['code_challenge'], $this->pkceChallenge($verifier))) {
            throw new RuntimeException('OAuth PKCE verifier does not match the authorization request.');
        }

        return $this->issueTokenSet($grant['client'], (int) $grant['user_id'], $grant['organization_id'] === null ? null : (int) $grant['organization_id'], (int) $grant['id'], $grant['scopes'], true, null, $now);
    }

    /** @param array<string, mixed> $input */
    private function clientCredentialsToken(array $input, DateTimeImmutable $now): array
    {
        $client = $this->authenticatedClient($input, 'client_credentials');
        $scopes = $this->constrainScopes($client, $this->parseScopes((string) ($input['scope'] ?? '')));

        return $this->issueTokenSet($client, null, $client->organizationId, null, $scopes, false, null, $now);
    }

    /** @param array<string, mixed> $input */
    private function refreshToken(array $input, DateTimeImmutable $now): array
    {
        $client = $this->authenticatedClient($input, 'refresh_token');
        $plainRefresh = trim((string) ($input['refresh_token'] ?? ''));
        if ($plainRefresh === '') {
            throw new InvalidArgumentException('Refresh token is required.');
        }

        $refresh = $this->tokens->findUsableRefreshToken(hash('sha256', $plainRefresh), $now);
        if ($refresh === null) {
            $this->tokens->markRefreshTokenReuse(hash('sha256', $plainRefresh), $now);
            throw new RuntimeException('OAuth refresh token is invalid, expired, revoked, or already rotated.');
        }

        if ((int) $refresh['client_id'] !== $client->id) {
            throw new RuntimeException('OAuth refresh token does not belong to this client.');
        }

        return $this->issueTokenSet($client, $refresh['user_id'] === null ? null : (int) $refresh['user_id'], $refresh['organization_id'] === null ? null : (int) $refresh['organization_id'], null, $refresh['scopes'], true, (int) $refresh['id'], $now);
    }

    /** @param list<string> $scopes */
    private function issueTokenSet(OAuthClient $client, ?int $userId, ?int $organizationId, ?int $authorizationCodeId, array $scopes, bool $includeRefresh, ?int $previousRefreshTokenId, DateTimeImmutable $now): array
    {
        $accessToken = $this->plainToken('voat_');
        $accessTokenId = $this->tokens->createAccessToken(
            $client,
            $userId,
            $organizationId,
            $authorizationCodeId,
            hash('sha256', $accessToken),
            $scopes,
            $now->add(new DateInterval('PT' . $this->accessTokenTtlSeconds . 'S')),
        );

        $data = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtlSeconds,
            'scope' => implode(' ', $scopes),
        ];

        if ($includeRefresh) {
            $refreshToken = $this->plainToken('vort_');
            $refreshTokenId = $this->tokens->createRefreshToken(
                $accessTokenId,
                $client,
                $userId,
                hash('sha256', $refreshToken),
                $previousRefreshTokenId,
                $now->add(new DateInterval('PT' . $this->refreshTokenTtlSeconds . 'S')),
            );
            if ($previousRefreshTokenId !== null) {
                $this->tokens->rotateRefreshToken($previousRefreshTokenId, $refreshTokenId, $now);
            }

            $data['refresh_token'] = $refreshToken;
            $data['refresh_expires_in'] = $this->refreshTokenTtlSeconds;
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function authenticatedClient(array $input, string $grantType): OAuthClient
    {
        $client = $this->requireClient((string) ($input['client_id'] ?? ''), $grantType);
        if ($client->isConfidential) {
            $secret = trim((string) ($input['client_secret'] ?? ''));
            if ($secret === '' || $client->secretHash === null || !$this->secrets->verify($secret, $client->secretHash)) {
                throw new RuntimeException('OAuth client authentication failed.');
            }
        }

        return $client;
    }

    private function requireClient(string $clientId, string $grantType): OAuthClient
    {
        $client = $this->clients->findActiveByIdentifier($clientId);
        if ($client === null || !in_array($grantType, $client->grantTypes, true)) {
            throw new RuntimeException('OAuth client is not authorized for this grant.');
        }

        if ($client->id === null) {
            throw new RuntimeException('OAuth client is missing a persisted ID.');
        }

        return $client;
    }

    /** @param list<string> $requested */
    private function constrainScopes(OAuthClient $client, array $requested): array
    {
        $requested = $requested === [] ? $client->scopes : array_values(array_unique($requested));
        foreach ($requested as $scope) {
            if (!in_array($scope, $client->scopes, true)) {
                throw new RuntimeException('OAuth scope is not allowed for this client.');
            }
        }

        return $requested;
    }

    /** @return list<string> */
    private function parseScopes(string $scope): array
    {
        return array_values(array_filter(array_unique(array_map('trim', preg_split('/\s+/', trim($scope)) ?: []))));
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function plainToken(string $prefix): string
    {
        if (is_callable($this->tokenFactory)) {
            return $prefix . ltrim((string) ($this->tokenFactory)(), '_');
        }

        return $prefix . bin2hex(random_bytes(32));
    }
}
