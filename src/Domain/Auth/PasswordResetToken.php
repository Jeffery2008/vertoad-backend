<?php

declare(strict_types=1);

namespace VertoAD\Domain\Auth;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PasswordResetToken
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $tokenHash,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
        public ?string $requestedIp,
        public ?string $userAgent,
    ) {
        if ($this->id !== null && $this->id <= 0) {
            throw new InvalidArgumentException('Password reset token ID must be positive when provided.');
        }

        if ($this->userId <= 0) {
            throw new InvalidArgumentException('Password reset token user ID must be positive.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $this->tokenHash) !== 1) {
            throw new InvalidArgumentException('Password reset token hash must be a SHA-256 hex digest.');
        }

        if ($this->userAgent !== null && mb_strlen($this->userAgent) > 512) {
            throw new InvalidArgumentException('Password reset token user agent must be 512 characters or fewer.');
        }
    }

    public function withId(int $id): self
    {
        return new self(
            id: $id,
            userId: $this->userId,
            tokenHash: $this->tokenHash,
            expiresAt: $this->expiresAt,
            usedAt: $this->usedAt,
            requestedIp: $this->requestedIp,
            userAgent: $this->userAgent,
        );
    }

    public function markUsed(DateTimeImmutable $usedAt): self
    {
        return new self(
            id: $this->id,
            userId: $this->userId,
            tokenHash: $this->tokenHash,
            expiresAt: $this->expiresAt,
            usedAt: $usedAt,
            requestedIp: $this->requestedIp,
            userAgent: $this->userAgent,
        );
    }
}
