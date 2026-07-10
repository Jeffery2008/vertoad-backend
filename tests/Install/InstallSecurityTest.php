<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Install\InstallHttpException;
use VertoAD\Install\InstallSecurity;

final class InstallSecurityTest extends TestCase
{
    private const TOKEN = 'vinstall_9Jp4wQ7xN2mK8rT5yH3cL6sD1fG0bV';

    public function testLocalGetAllowsHttpAndIssuesHostScopedCsrfCookie(): void
    {
        $security = new InstallSecurity('', static fn (): string => 'csrf-local');
        $request = $this->request('GET', 'http://localhost/install', '127.23.4.5');

        self::assertTrue($security->assertCanRender($request));
        self::assertSame('csrf-local', $security->issueCsrfToken());
        $response = $security->withCsrfCookie((new ResponseFactory())->createResponse(), 'csrf-local', $request);

        self::assertSame(
            'vertoad_install_csrf=csrf-local; Path=/install; HttpOnly; SameSite=Strict; Max-Age=600',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    public function testHttpsCookieIsSecureAndCanBeCleared(): void
    {
        $security = new InstallSecurity(self::TOKEN);
        $request = $this->request('GET', 'https://api.example.test/install', '198.51.100.5');
        $response = $security->withCsrfCookie((new ResponseFactory())->createResponse(), '', $request, clear: true);

        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertFalse($security->assertCanRender($request));
        self::assertSame(43, strlen($security->issueCsrfToken()));
    }

    public function testRemoteHttpsCanAlsoBeDetectedFromServerFlag(): void
    {
        $request = $this->request('GET', 'http://api.example.test/install', '198.51.100.5', ['HTTPS' => 'ON']);

        self::assertFalse((new InstallSecurity(self::TOKEN))->assertCanRender($request));
    }

    #[DataProvider('renderRejectionProvider')]
    public function testRenderRejectsUnsafeRequests(
        string $uri,
        ?string $remoteAddress,
        string $configuredToken,
        int $status,
        string $code,
    ): void {
        $request = $this->request('GET', $uri, $remoteAddress);

        try {
            (new InstallSecurity($configuredToken))->assertCanRender($request);
            self::fail('Expected installer request to be rejected.');
        } catch (InstallHttpException $exception) {
            self::assertSame($status, $exception->statusCode);
            self::assertSame($code, $exception->errorCode);
            self::assertNotSame('', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, ?string, string, int, string}> */
    public static function renderRejectionProvider(): iterable
    {
        yield 'token in query' => ['https://api.example.test/install?install_token=secret', '198.51.100.5', self::TOKEN, 400, 'install_token_in_query'];
        yield 'generic token in query' => ['https://api.example.test/install?TOKEN=secret', '198.51.100.5', self::TOKEN, 400, 'install_token_in_query'];
        yield 'nested token in query' => ['https://api.example.test/install?credentials[install-token]=secret', '198.51.100.5', self::TOKEN, 400, 'install_token_in_query'];
        yield 'remote plain HTTP' => ['http://api.example.test/install', '198.51.100.5', self::TOKEN, 426, 'https_required'];
        yield 'loopback socket with public host' => ['http://attacker.example/install', '127.0.0.1', self::TOKEN, 426, 'https_required'];
        yield 'missing remote token' => ['https://api.example.test/install', '198.51.100.5', '', 503, 'install_token_not_configured'];
        yield 'low diversity remote token' => ['https://api.example.test/install', '198.51.100.5', str_repeat('a', 40), 503, 'install_token_not_configured'];
        yield 'missing socket address is remote' => ['https://api.example.test/install', null, '', 503, 'install_token_not_configured'];
        yield 'invalid socket address is remote' => ['https://api.example.test/install', 'not-an-ip', '', 503, 'install_token_not_configured'];
    }

    public function testValidRemotePostAcceptsBodyCredentials(): void
    {
        $security = new InstallSecurity(self::TOKEN);
        $request = $this->remotePost([
            'Origin' => 'https://api.example.test/',
        ]);

        self::assertFalse($security->assertCanInstall($request, [
            '_csrf' => 'csrf-value',
            'install_token' => self::TOKEN,
        ]));
    }

    public function testOriginNormalizationAcceptsExplicitDefaultHttpsPortAndServerTlsFlag(): void
    {
        $security = new InstallSecurity(self::TOKEN);
        $request = $this->request('POST', 'http://api.example.test/install', '198.51.100.5', ['HTTPS' => 'on'])
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-value'])
            ->withHeader('Origin', 'https://api.example.test:443');

        self::assertFalse($security->assertCanInstall($request, [
            '_csrf' => 'csrf-value',
            'install_token' => self::TOKEN,
        ]));
    }

    public function testValidRemotePostAcceptsHeaderCredentialsWithoutOrigin(): void
    {
        $security = new InstallSecurity(self::TOKEN);
        $request = $this->remotePost([
            'X-Install-CSRF' => 'csrf-value',
            'X-Install-Token' => self::TOKEN,
        ]);

        self::assertFalse($security->assertCanInstall($request, []));
    }

    public function testIpv6LoopbackPostDoesNotRequireInstallToken(): void
    {
        $security = new InstallSecurity('');
        $request = $this->request('POST', 'http://localhost/install', '::1')
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-value']);

        self::assertTrue($security->assertCanInstall($request, ['_csrf' => 'csrf-value']));
    }

    public function testIpv4MappedIpv6LoopbackIsLocalOnlyWithLocalhostHost(): void
    {
        $security = new InstallSecurity('');
        $request = $this->request('POST', 'http://127.0.0.9/install', '::ffff:127.0.0.8')
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-value']);

        self::assertTrue($security->assertCanInstall($request, ['_csrf' => 'csrf-value']));
    }

    #[DataProvider('postRejectionProvider')]
    public function testPostRejectsInvalidAuthorizationOrCsrf(array $headers, array $body, string $code): void
    {
        $request = $this->remotePost($headers);

        try {
            (new InstallSecurity(self::TOKEN))->assertCanInstall($request, $body);
            self::fail('Expected installer POST to be rejected.');
        } catch (InstallHttpException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertSame($code, $exception->errorCode);
        }
    }

    /** @return iterable<string, array{array<string, string>, array<string, string>, string}> */
    public static function postRejectionProvider(): iterable
    {
        yield 'cross site metadata' => [['Sec-Fetch-Site' => 'cross-site'], ['_csrf' => 'csrf-value', 'install_token' => self::TOKEN], 'cross_site_request_blocked'];
        yield 'origin mismatch' => [['Origin' => 'https://attacker.example'], ['_csrf' => 'csrf-value', 'install_token' => self::TOKEN], 'origin_validation_failed'];
        yield 'origin scheme' => [['Origin' => 'ftp://api.example.test'], ['_csrf' => 'csrf-value', 'install_token' => self::TOKEN], 'origin_validation_failed'];
        yield 'origin path' => [['Origin' => 'https://api.example.test/not-an-origin'], ['_csrf' => 'csrf-value', 'install_token' => self::TOKEN], 'origin_validation_failed'];
        yield 'csrf conflict' => [['X-Install-CSRF' => 'different'], ['_csrf' => 'csrf-value', 'install_token' => self::TOKEN], 'csrf_conflict'];
        yield 'csrf missing' => [[], ['install_token' => self::TOKEN], 'csrf_validation_failed'];
        yield 'token conflict' => [['X-Install-Token' => self::TOKEN], ['_csrf' => 'csrf-value', 'install_token' => 'different-token'], 'install_token_conflict'];
        yield 'token invalid' => [[], ['_csrf' => 'csrf-value', 'install_token' => 'wrong'], 'invalid_install_token'];
        yield 'token missing' => [[], ['_csrf' => 'csrf-value'], 'invalid_install_token'];
    }

    public function testOriginCannotBeValidatedWhenRequestUriIsRelative(): void
    {
        $request = $this->request('POST', '/install', '198.51.100.5', ['HTTPS' => 'on'])
            ->withHeader('Origin', 'https://api.example.test')
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-value']);

        $this->expectException(InstallHttpException::class);
        $this->expectExceptionMessage('could not be validated');
        (new InstallSecurity(self::TOKEN))->assertCanInstall($request, [
            '_csrf' => 'csrf-value',
            'install_token' => self::TOKEN,
        ]);
    }

    /** @param array<string, string> $headers */
    private function remotePost(array $headers): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->request('POST', 'https://api.example.test/install', '198.51.100.5')
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-value']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /** @param array<string, mixed> $extraServerParams */
    private function request(string $method, string $uri, ?string $remoteAddress, array $extraServerParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        $server = $extraServerParams;
        if ($remoteAddress !== null) {
            $server['REMOTE_ADDR'] = $remoteAddress;
        }

        return (new ServerRequestFactory())->createServerRequest($method, $uri, $server);
    }
}
