<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

final readonly class WithdrawalProofPolicy
{
    public const int DEFAULT_MAX_BYTES = 10_485_760;

    /** @var array<string, string> */
    private const array CONTENT_TYPES_BY_EXTENSION = [
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'pdf' => 'application/pdf',
        'png' => 'image/png',
    ];

    public function __construct(public int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    public function contentTypeForExtension(string $extension): ?string
    {
        return self::CONTENT_TYPES_BY_EXTENSION[strtolower(trim($extension))] ?? null;
    }

    public function matchesMagic(string $contentType, string $bytes): bool
    {
        return match (strtolower(trim($contentType))) {
            'application/pdf' => str_starts_with($bytes, '%PDF-'),
            'image/jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            default => false,
        };
    }
}
