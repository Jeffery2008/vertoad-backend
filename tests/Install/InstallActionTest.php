<?php

declare(strict_types=1);

namespace VertoAD\Tests\Install;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\Http\Action\Install\InstallAction;
use VertoAD\Http\Action\Install\InstalledInstallAction;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Install\InstallHttpException;
use VertoAD\Install\InstallInput;
use VertoAD\Install\InstallerInterface;
use VertoAD\Install\InstallSecurity;

final class InstallActionTest extends TestCase
{
    private const INSTALL_TOKEN = 'vinstall_9Jp4wQ7xN2mK8rT5yH3cL6sD1fG0bV';

    protected function tearDown(): void
    {
        RequestIdContext::clear();
    }

    public function testLocalGetRendersSecureFormWithoutRemoteTokenField(): void
    {
        $action = new InstallAction(
            new InstallSecurity('', static fn (): string => 'csrf-<local>'),
            $this->installer($this->installResult()),
        );
        $response = $action(
            $this->request('GET', 'http://localhost/install', '127.0.0.1'),
            (new ResponseFactory())->createResponse(),
        );
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Secure installation', $body);
        self::assertStringContainsString('value="csrf-&lt;local&gt;"', $body);
        self::assertStringNotContainsString('name="install_token"', $body);
        self::assertStringContainsString('vertoad_install_csrf=csrf-%3Clocal%3E', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertStringContainsString("form-action 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testRemoteGetRendersTokenFieldAndSecureCookie(): void
    {
        $action = new InstallAction(
            new InstallSecurity(self::INSTALL_TOKEN, static fn (): string => 'csrf-remote'),
            $this->installer($this->installResult()),
        );
        $response = $action(
            $this->request('GET', 'https://api.example.test/install', '198.51.100.8'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertStringContainsString('name="install_token"', (string) $response->getBody());
        self::assertStringContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testGetSecurityFailureCanReturnJsonWithoutLeakingToken(): void
    {
        $action = new InstallAction(new InstallSecurity(self::INSTALL_TOKEN), $this->installer($this->installResult()));
        $request = $this->request('GET', 'https://api.example.test/install?token=top-secret', '198.51.100.8')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Request-Id', 'install-get-error');
        $response = $action($request, (new ResponseFactory())->createResponse());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('install_token_in_query', $payload['error']['code']);
        self::assertSame('install-get-error', $payload['request_id']);
        self::assertStringNotContainsString('top-secret', (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testLocalPostInstallsAndEscapesPublicOauthClientIdInHtml(): void
    {
        $result = $this->installResult();
        $result['oauth_client_id'] = 'voc_<client>';
        $calls = new \ArrayObject();
        $action = new InstallAction(new InstallSecurity(''), $this->installer($result, $calls));
        $body = InstallTestInput::valid(local: true) + ['_csrf' => 'csrf-local'];
        $request = $this->request('POST', 'http://localhost/install', '127.0.0.1')
            ->withParsedBody($body)
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-local'])
            ->withHeader('X-Request-Id', 'install-post-html');
        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('Installation complete', (string) $response->getBody());
        self::assertStringContainsString('voc_&lt;client&gt;', (string) $response->getBody());
        self::assertStringContainsString('Authorization Code + S256 PKCE', (string) $response->getBody());
        self::assertStringContainsString('No client secret is issued', (string) $response->getBody());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertTrue($calls[0]['local_installation']);
    }

    public function testRemotePostInstallsWithHeaderCredentialsAndReturnsJson(): void
    {
        $calls = new \ArrayObject();
        $action = new InstallAction(
            new InstallSecurity(self::INSTALL_TOKEN),
            $this->installer($this->installResult(), $calls),
            new ClientIpResolver('CF-Connecting-IP', ['192.0.2.0/24']),
        );
        $request = $this->request('POST', 'https://api.example.test/install', '192.0.2.10')
            ->withParsedBody(InstallTestInput::valid())
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-remote'])
            ->withHeader('X-Install-CSRF', 'csrf-remote')
            ->withHeader('X-Install-Token', self::INSTALL_TOKEN)
            ->withHeader('Origin', 'https://api.example.test')
            ->withHeader('Accept', 'application/json')
            ->withHeader('CF-Connecting-IP', '203.0.113.44')
            ->withHeader('User-Agent', str_repeat('a', 600))
            ->withHeader('X-Request-Id', 'install-post-json');
        $response = $action($request, (new ResponseFactory())->createResponse());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($payload['installed']);
        self::assertSame('voc_test_client', $payload['oauth_client_id']);
        self::assertSame('public', $payload['oauth_client_type']);
        self::assertTrue($payload['oauth_pkce_required']);
        self::assertArrayNotHasKey('oauth_client_secret', $payload);
        self::assertArrayNotHasKey('credentials_shown_once', $payload);
        self::assertStringContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
        self::assertCount(1, $calls);
        self::assertFalse($calls[0]['local_installation']);
        self::assertSame('203.0.113.44', $calls[0]['client_ip']);
        self::assertSame(512, strlen($calls[0]['user_agent']));
        self::assertSame('install-post-json', $calls[0]['request_id']);
    }

    public function testPostRejectsMissingParsedBody(): void
    {
        $action = new InstallAction(new InstallSecurity(''), $this->installer($this->installResult()));
        $request = $this->request('POST', 'http://localhost/install', '127.0.0.1')
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-local']);
        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('request body must be an object', (string) $response->getBody());
    }

    public function testPostReturnsValidationErrorForInvalidInput(): void
    {
        $action = new InstallAction(new InstallSecurity(''), $this->installer($this->installResult()));
        $request = $this->request('POST', 'http://localhost/install', '127.0.0.1')
            ->withParsedBody(['_csrf' => 'csrf-local'])
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-local']);
        $response = $action($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Db host is required', (string) $response->getBody());
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testInstallerHttpFailureKeepsStatusAndPublicErrorCode(): void
    {
        $action = new InstallAction(
            new InstallSecurity(''),
            $this->installer(new InstallHttpException(409, 'installation_in_progress', 'Please retry.')),
        );
        $response = $action($this->validLocalPost(), (new ResponseFactory())->createResponse());

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('Please retry.', (string) $response->getBody());
    }

    public function testUnexpectedInstallerFailureReturnsGenericJsonError(): void
    {
        $action = new InstallAction(
            new InstallSecurity(''),
            $this->installer(new \RuntimeException('sensitive database detail')),
        );
        $response = $action(
            $this->validLocalPost()->withHeader('Accept', 'application/json'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('installation_failed', (string) $response->getBody());
        self::assertStringNotContainsString('sensitive database detail', (string) $response->getBody());
    }

    public function testInstalledActionAlwaysReturnsGoneWithoutExecutingInstaller(): void
    {
        $response = (new InstalledInstallAction())(
            $this->request('POST', 'https://api.example.test/install', '198.51.100.8'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(410, $response->getStatusCode());
        self::assertStringContainsString('Installer disabled', (string) $response->getBody());
        self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    private function validLocalPost(): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->request('POST', 'http://localhost/install', '127.0.0.1')
            ->withParsedBody(InstallTestInput::valid(local: true) + ['_csrf' => 'csrf-local'])
            ->withCookieParams(['vertoad_install_csrf' => 'csrf-local']);
    }

    /** @return array{installation_id: string, admin_user_id: int, organization_id: int, oauth_client_id: string} */
    private function installResult(): array
    {
        return [
            'installation_id' => '0123456789abcdef0123456789abcdef',
            'admin_user_id' => 1,
            'organization_id' => 2,
            'oauth_client_id' => 'voc_test_client',
        ];
    }

    private function installer(array|\Throwable $outcome, ?\ArrayObject $calls = null): InstallerInterface
    {
        return new class($outcome, $calls) implements InstallerInterface {
            public function __construct(private array|\Throwable $outcome, private ?\ArrayObject $calls)
            {
            }

            public function install(
                InstallInput $input,
                bool $localInstallation,
                ?string $clientIp,
                ?string $userAgent,
                string $requestId,
            ): array {
                if ($this->calls !== null) {
                    $this->calls->append([
                        'local_installation' => $localInstallation,
                        'client_ip' => $clientIp,
                        'user_agent' => $userAgent,
                        'request_id' => $requestId,
                    ]);
                }
                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };
    }

    private function request(string $method, string $uri, string $remoteAddress): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $remoteAddress]);
    }
}
