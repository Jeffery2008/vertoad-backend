<?php

declare(strict_types=1);

namespace VertoAD\Service\Webhooks;

final readonly class WebhookSigner
{
    public function __construct(private string $secret)
    {
    }

    public function signatureHeader(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $this->secret);
    }

    public function verify(string $payload, string $header): bool
    {
        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $matches) !== 1) {
            return false;
        }

        return hash_equals($this->signatureHeader($payload, (int) $matches[1]), $header);
    }
}
