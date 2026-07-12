<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OAuthClient
{
    public ?int $id;
    public ?int $organizationId;
    public ?int $ownerUserId;
    public string $clientIdentifier;
    public string $name;
    public ?string $secretHash;
    /** @var list<string> */
    public array $redirectUris;
    /** @var list<string> */
    public array $grantTypes;
    /** @var list<string> */
    public array $scopes;
    public bool $isConfidential;
    public ?DateTimeImmutable $revokedAt;

    /**
     * @param list<string> $redirectUris
     * @param list<string> $grantTypes
     * @param list<string> $scopes
     */
    public function __construct(
        ?int $id,
        ?int $organizationId,
        ?int $ownerUserId,
        string $clientIdentifier,
        string $name,
        ?string $secretHash,
        array $redirectUris,
        array $grantTypes,
        array $scopes,
        bool $isConfidential,
        ?DateTimeImmutable $revokedAt,
    ) {
        $clientIdentifier = trim($clientIdentifier);
        $name = trim($name);
        $secretHash = $secretHash === null ? null : trim($secretHash);
        $redirectUris = self::normalizeOrderedList($redirectUris, sort: false);
        $grantTypes = self::normalizeOrderedList($grantTypes, sort: false);
        $scopes = self::normalizeOrderedList($scopes, sort: true);

        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('OAuth client ID must be positive when provided.');
        }

        if ($organizationId !== null && $organizationId <= 0) {
            throw new InvalidArgumentException('OAuth client organization ID must be positive when provided.');
        }

        if ($ownerUserId !== null && $ownerUserId <= 0) {
            throw new InvalidArgumentException('OAuth client owner user ID must be positive when provided.');
        }

        if ($clientIdentifier === '') {
            throw new InvalidArgumentException('OAuth client identifier is required.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('OAuth client name is required.');
        }

        if ($secretHash !== null && $secretHash === '') {
            throw new InvalidArgumentException('OAuth client secret hash must not be blank when provided.');
        }

        if ($redirectUris === []) {
            throw new InvalidArgumentException('OAuth client requires at least one redirect URI.');
        }

        if ($grantTypes === []) {
            throw new InvalidArgumentException('OAuth client requires at least one grant type.');
        }

        foreach ($redirectUris as $uri) {
            self::validateRedirectUri($uri);
        }

        foreach ($grantTypes as $grantType) {
            if (!in_array($grantType, ['authorization_code', 'client_credentials', 'refresh_token'], true)) {
                throw new InvalidArgumentException('OAuth client grant type is not supported.');
            }
        }

        if ($isConfidential && $secretHash === null) {
            throw new InvalidArgumentException('Confidential OAuth clients require a secret hash.');
        }

        if (!$isConfidential && $secretHash !== null) {
            throw new InvalidArgumentException('Public OAuth clients must not have a client secret.');
        }

        if (!$isConfidential && in_array('client_credentials', $grantTypes, true)) {
            throw new InvalidArgumentException('Public OAuth clients cannot use the client_credentials grant.');
        }

        foreach ($scopes as $scope) {
            if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+$/', $scope) !== 1) {
                throw new InvalidArgumentException('OAuth client scope must use dot-separated permission code format.');
            }
        }

        $this->id = $id;
        $this->organizationId = $organizationId;
        $this->ownerUserId = $ownerUserId;
        $this->clientIdentifier = $clientIdentifier;
        $this->name = $name;
        $this->secretHash = $secretHash;
        $this->redirectUris = $redirectUris;
        $this->grantTypes = $grantTypes;
        $this->scopes = $scopes;
        $this->isConfidential = $isConfidential;
        $this->revokedAt = $revokedAt;
    }

    public function normalized(): self
    {
        return $this;
    }

    public function withId(int $id): self
    {
        $normalized = $this->normalized();

        return new self(
            id: $id,
            organizationId: $normalized->organizationId,
            ownerUserId: $normalized->ownerUserId,
            clientIdentifier: $normalized->clientIdentifier,
            name: $normalized->name,
            secretHash: $normalized->secretHash,
            redirectUris: $normalized->redirectUris,
            grantTypes: $normalized->grantTypes,
            scopes: $normalized->scopes,
            isConfidential: $normalized->isConfidential,
            revokedAt: $normalized->revokedAt,
        );
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    private static function validateRedirectUri(string $uri): void
    {
        $uri = trim($uri);
        if ($uri === '' || filter_var($uri, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('OAuth client redirect URI must be a valid URL.');
        }

        $parts = parse_url($uri);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))) {
            return;
        }

        throw new InvalidArgumentException('OAuth client redirect URI must use HTTPS except localhost development URLs.');
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function normalizeOrderedList(array $values, bool $sort): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (string $value): string => trim($value),
            array_filter($values, static fn (string $value): bool => trim($value) !== ''),
        )));

        if ($sort) {
            sort($normalized);
        }

        return $normalized;
    }
}
