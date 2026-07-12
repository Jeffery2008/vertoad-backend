<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * Keeps provider-managed R2 encryption distinct from AWS-style SSE metadata.
 *
 * Cloudflare R2 encrypts every object at rest with AES-256, but its S3 API does
 * not implement x-amz-server-side-encryption. The explicit R2-AES256 mode is
 * accepted only for the account S3 endpoint over HTTPS; other S3 providers must
 * continue to return the configured SSE metadata.
 */
final readonly class S3EncryptionPolicy
{
    public const R2_AES256 = 'R2-AES256';

    private function __construct(
        public ?string $mode,
        public bool $providerManaged,
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(
        array $config,
        string $context,
        ?string $default = null,
    ): self {
        $configured = trim((string) ($config['server_side_encryption'] ?? ''));
        $mode = $configured === '' ? $default : $configured;
        $mode = $mode === null ? null : trim($mode);

        if ($mode === null || $mode === '') {
            return new self(null, false);
        }

        if (!in_array($mode, ['AES256', 'aws:kms', self::R2_AES256], true)) {
            throw new InvalidArgumentException(
                $context . ' server-side encryption must be AES256, aws:kms, or R2-AES256.'
            );
        }

        if ($mode !== self::R2_AES256) {
            return new self($mode, false);
        }

        $endpoint = trim((string) ($config['endpoint'] ?? ''));
        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if (
            $scheme !== 'https'
            || !str_ends_with($host, '.r2.cloudflarestorage.com')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                $context . ' R2-AES256 requires a credential-free HTTPS Cloudflare R2 account endpoint.'
            );
        }

        return new self(self::R2_AES256, true);
    }

    /** @return array<string, string> */
    public function putParameters(): array
    {
        if ($this->mode === null || $this->providerManaged) {
            return [];
        }

        return ['ServerSideEncryption' => $this->mode];
    }

    /** @param array<string, mixed> $metadata */
    public function assertMetadata(array $metadata, string $errorMessage): void
    {
        if ($this->mode === null || $this->providerManaged) {
            return;
        }

        $actual = trim((string) ($metadata['ServerSideEncryption'] ?? ''));
        if ($actual !== $this->mode) {
            throw new RuntimeException($errorMessage);
        }
    }

    public function readinessEvidence(): string
    {
        return $this->providerManaged
            ? 'cloudflare-r2-managed-aes256'
            : ($this->mode ?? 'none');
    }
}
