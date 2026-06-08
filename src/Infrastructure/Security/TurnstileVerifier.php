<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

use JsonException;
use RuntimeException;
use Throwable;

final readonly class TurnstileVerifier
{
    /**
     * @param callable(string, array<string, string>): array<string, mixed>|null $transport
     */
    public function __construct(
        private string $secretKey,
        private string $verifyUrl,
        private mixed $transport = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->secretKey) !== '';
    }

    public function verify(?string $token, ?string $remoteIp): TurnstileVerificationResult
    {
        $token = trim((string) $token);
        if ($token === '') {
            return new TurnstileVerificationResult(
                success: false,
                code: 'turnstile_token_required',
                message: 'Turnstile token is required.',
                providerAvailable: false,
            );
        }

        if (!$this->isConfigured()) {
            return new TurnstileVerificationResult(
                success: true,
                code: 'turnstile_not_configured',
                message: 'Turnstile verification is not configured.',
                providerAvailable: false,
            );
        }

        try {
            $payload = [
                'secret' => $this->secretKey,
                'response' => $token,
            ];
            if ($remoteIp !== null && trim($remoteIp) !== '') {
                $payload['remoteip'] = trim($remoteIp);
            }

            $data = $this->callProvider($payload);
        } catch (Throwable) {
            return new TurnstileVerificationResult(
                success: false,
                code: 'turnstile_provider_unavailable',
                message: 'Turnstile verification provider is unavailable.',
                providerAvailable: false,
            );
        }

        if (($data['success'] ?? false) === true) {
            return new TurnstileVerificationResult(
                success: true,
                code: 'turnstile_verified',
                message: 'Turnstile verification succeeded.',
                providerAvailable: true,
            );
        }

        return new TurnstileVerificationResult(
            success: false,
            code: 'turnstile_verification_failed',
            message: 'Turnstile verification failed.',
            providerAvailable: true,
            errorCodes: $this->errorCodes($data['error-codes'] ?? []),
        );
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function callProvider(array $payload): array
    {
        if (is_callable($this->transport)) {
            $result = ($this->transport)($this->verifyUrl, $payload);
            if (!is_array($result)) {
                throw new RuntimeException('Turnstile transport returned an invalid response.');
            }

            return $result;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($this->verifyUrl, false, $context);
        if ($body === false) {
            throw new RuntimeException('Turnstile provider request failed.');
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Turnstile provider returned invalid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Turnstile provider returned an invalid response.');
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function errorCodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }
}
