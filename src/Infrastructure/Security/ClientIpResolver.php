<?php

declare(strict_types=1);

namespace VertoAD\Infrastructure\Security;

use Psr\Http\Message\ServerRequestInterface;

final readonly class ClientIpResolver
{
    /**
     * @param list<string> $trustedProxies
     */
    public function __construct(
        private string $realIpHeader = 'CF-Connecting-IP',
        private array $trustedProxies = [],
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings): self
    {
        $header = trim((string) ($settings['real_ip_header'] ?? 'CF-Connecting-IP'));
        $trustedProxies = $settings['trusted_proxies'] ?? [];

        return new self(
            $header === '' ? 'CF-Connecting-IP' : $header,
            is_array($trustedProxies)
                ? array_values(array_filter(array_map(
                    static fn (mixed $proxy): string => trim((string) $proxy),
                    $trustedProxies,
                )))
                : [],
        );
    }

    public function resolve(ServerRequestInterface $request): ?string
    {
        $remoteIp = $this->validIp($request->getServerParams()['REMOTE_ADDR'] ?? null);
        $forwardedIp = $this->validIp($this->firstHeaderValue($request->getHeaderLine($this->realIpHeader)));

        if ($forwardedIp !== null && $this->trustsForwardedHeader($remoteIp)) {
            return $forwardedIp;
        }

        return $remoteIp;
    }

    private function trustsForwardedHeader(?string $remoteIp): bool
    {
        if ($this->trustedProxies === []) {
            return false;
        }

        if ($remoteIp === null) {
            return false;
        }

        foreach ($this->trustedProxies as $trustedProxy) {
            if ($this->matchesTrustedProxy($remoteIp, $trustedProxy)) {
                return true;
            }
        }

        return false;
    }

    private function matchesTrustedProxy(string $ipAddress, string $trustedProxy): bool
    {
        if (!str_contains($trustedProxy, '/')) {
            return $this->validIp($trustedProxy) === $ipAddress;
        }

        [$network, $prefix] = explode('/', $trustedProxy, 2);
        $networkPacked = @inet_pton(trim($network));
        $ipPacked = @inet_pton($ipAddress);
        if ($networkPacked === false || $ipPacked === false || strlen($networkPacked) !== strlen($ipPacked)) {
            return false;
        }

        if (!ctype_digit($prefix)) {
            return false;
        }

        $prefixBits = (int) $prefix;
        $totalBits = strlen($ipPacked) * 8;
        if ($prefixBits < 0 || $prefixBits > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($prefixBits, 8);
        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefixBits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }

    private function firstHeaderValue(string $value): ?string
    {
        $first = trim(explode(',', $value, 2)[0]);

        return $first === '' ? null : $first;
    }

    private function validIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || @inet_pton($value) === false) {
            return null;
        }

        return $value;
    }
}
