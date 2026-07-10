<?php

declare(strict_types=1);

namespace VertoAD\Install;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class InstallSecurity
{
    private const CSRF_COOKIE = 'vertoad_install_csrf';

    /** @param (callable(): string)|null $nonceFactory */
    public function __construct(
        #[\SensitiveParameter]
        private string $configuredToken,
        private mixed $nonceFactory = null,
    ) {
    }

    public function assertCanRender(ServerRequestInterface $request): bool
    {
        $this->assertNoQueryToken($request);
        $local = $this->isLoopback($request);
        $this->assertTransportAndConfiguration($request, $local);

        return $local;
    }

    /** @param array<string, mixed> $input */
    public function assertCanInstall(ServerRequestInterface $request, #[\SensitiveParameter] array $input): bool
    {
        $local = $this->assertCanRender($request);
        $this->assertSameOrigin($request);
        $this->assertCsrf($request, $input);

        if (!$local) {
            $this->assertInstallToken($request, $input);
        }

        return $local;
    }

    public function issueCsrfToken(): string
    {
        if (is_callable($this->nonceFactory)) {
            return (string) ($this->nonceFactory)();
        }

        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function withCsrfCookie(
        ResponseInterface $response,
        string $token,
        ServerRequestInterface $request,
        bool $clear = false,
    ): ResponseInterface {
        $parts = [
            self::CSRF_COOKIE . '=' . ($clear ? '' : rawurlencode($token)),
            'Path=/install',
            'HttpOnly',
            'SameSite=Strict',
            $clear ? 'Max-Age=0' : 'Max-Age=600',
        ];
        if ($this->isHttps($request)) {
            $parts[] = 'Secure';
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    private function assertNoQueryToken(ServerRequestInterface $request): void
    {
        if ($this->containsQueryTokenKey($request->getQueryParams())) {
            throw new InstallHttpException(400, 'install_token_in_query', 'Installation tokens must never be sent in a URL.');
        }
    }

    /** @param array<string|int, mixed> $values */
    private function containsQueryTokenKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            $normalizedKey = str_replace('-', '_', strtolower((string) $key));
            if (in_array($normalizedKey, ['install_token', 'token'], true)) {
                return true;
            }
            if (is_array($value) && $this->containsQueryTokenKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function assertTransportAndConfiguration(ServerRequestInterface $request, bool $local): void
    {
        if ($local) {
            return;
        }

        if (!$this->isHttps($request)) {
            throw new InstallHttpException(426, 'https_required', 'Remote installation requires HTTPS.');
        }

        if (!$this->isStrongToken($this->configuredToken)) {
            throw new InstallHttpException(503, 'install_token_not_configured', 'Remote installation is disabled until a strong INSTALL_TOKEN is configured.');
        }
    }

    /** @param array<string, mixed> $input */
    private function assertInstallToken(ServerRequestInterface $request, #[\SensitiveParameter] array $input): void
    {
        $headerToken = trim($request->getHeaderLine('X-Install-Token'));
        $bodyToken = trim((string) ($input['install_token'] ?? ''));
        if ($headerToken !== '' && $bodyToken !== '' && !$this->constantTimeEquals($headerToken, $bodyToken)) {
            throw new InstallHttpException(403, 'install_token_conflict', 'Conflicting installation credentials were provided.');
        }

        $provided = $headerToken !== '' ? $headerToken : $bodyToken;
        if ($provided === '' || !$this->constantTimeEquals($this->configuredToken, $provided)) {
            throw new InstallHttpException(403, 'invalid_install_token', 'Installation authorization failed.');
        }
    }

    /** @param array<string, mixed> $input */
    private function assertCsrf(ServerRequestInterface $request, array $input): void
    {
        $bodyToken = trim((string) ($input['_csrf'] ?? ''));
        $headerToken = trim($request->getHeaderLine('X-Install-CSRF'));
        if ($bodyToken !== '' && $headerToken !== '' && !$this->constantTimeEquals($bodyToken, $headerToken)) {
            throw new InstallHttpException(403, 'csrf_conflict', 'Conflicting CSRF credentials were provided.');
        }

        $provided = $headerToken !== '' ? $headerToken : $bodyToken;
        $cookie = $request->getCookieParams()[self::CSRF_COOKIE] ?? '';
        if (!is_string($cookie) || $provided === '' || !$this->constantTimeEquals($cookie, $provided)) {
            throw new InstallHttpException(403, 'csrf_validation_failed', 'Installation CSRF validation failed.');
        }
    }

    private function assertSameOrigin(ServerRequestInterface $request): void
    {
        if (strtolower(trim($request->getHeaderLine('Sec-Fetch-Site'))) === 'cross-site') {
            throw new InstallHttpException(403, 'cross_site_request_blocked', 'Cross-site installation requests are blocked.');
        }

        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return;
        }

        $uri = $request->getUri();
        $scheme = $this->isHttps($request) ? 'https' : strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());
        if ($scheme === '' || $host === '') {
            throw new InstallHttpException(403, 'origin_validation_failed', 'The installation request origin could not be validated.');
        }

        $originParts = parse_url($origin);
        if (
            !is_array($originParts)
            || isset($originParts['user'])
            || isset($originParts['pass'])
            || isset($originParts['query'])
            || isset($originParts['fragment'])
            || !in_array((string) ($originParts['path'] ?? ''), ['', '/'], true)
        ) {
            throw new InstallHttpException(403, 'origin_validation_failed', 'The installation request origin could not be validated.');
        }

        $expected = $this->normalizeOrigin($scheme, $host, $uri->getPort());
        $provided = $this->normalizeOrigin(
            strtolower((string) ($originParts['scheme'] ?? '')),
            strtolower((string) ($originParts['host'] ?? '')),
            isset($originParts['port']) ? (int) $originParts['port'] : null,
        );
        if ($expected === null || $provided === null || !$this->constantTimeEquals($expected, $provided)) {
            throw new InstallHttpException(403, 'origin_validation_failed', 'The installation request origin does not match this server.');
        }
    }

    private function normalizeOrigin(string $scheme, string $host, ?int $port): ?string
    {
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $effectivePort = $port ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . "\0" . $host . "\0" . $effectivePort;
    }

    private function isLoopback(ServerRequestInterface $request): bool
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote)
            && $this->isLoopbackAddress($remote)
            && $this->isLoopbackHost($request->getUri()->getHost());
    }

    private function isLoopbackAddress(string $address): bool
    {
        $address = strtolower(trim($address, " \t\n\r\0\x0B[]"));
        if ($address === '::1') {
            return true;
        }
        if (str_starts_with($address, '::ffff:')) {
            $address = substr($address, 7);
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($address, '127.');
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        return $host === 'localhost' || $this->isLoopbackAddress($host);
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getUri()->getScheme()) === 'https') {
            return true;
        }

        return in_array(strtolower((string) ($request->getServerParams()['HTTPS'] ?? '')), ['1', 'on', 'true'], true);
    }

    private function isStrongToken(string $token): bool
    {
        return strlen($token) >= 32 && count(array_unique(str_split($token))) >= 12;
    }

    private function constantTimeEquals(#[\SensitiveParameter] string $expected, #[\SensitiveParameter] string $provided): bool
    {
        return hash_equals(hash('sha256', $expected, true), hash('sha256', $provided, true));
    }
}
