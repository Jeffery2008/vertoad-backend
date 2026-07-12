<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Install;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\RequestIdContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Install\InstallHttpException;
use VertoAD\Install\InstallInput;
use VertoAD\Install\InstallerInterface;
use VertoAD\Install\InstallSecurity;

final readonly class InstallAction
{
    private ClientIpResolver $clientIpResolver;

    public function __construct(
        private InstallSecurity $security,
        private InstallerInterface $installer,
        ?ClientIpResolver $clientIpResolver = null,
    ) {
        $this->clientIpResolver = $clientIpResolver ?? new ClientIpResolver();
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            if (strtoupper($request->getMethod()) === 'GET') {
                return $this->renderForm($request, $response);
            }

            return $this->install($request, $response);
        } catch (InstallHttpException $exception) {
            return $this->error($request, $response, $exception->statusCode, $exception->errorCode, $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->error($request, $response, 422, 'invalid_installation_input', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('VertoAD installer failure: ' . $exception::class);

            return $this->error($request, $response, 500, 'installation_failed', 'Installation failed. Review the server log and retry after correcting the cause.');
        }
    }

    private function renderForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $local = $this->security->assertCanRender($request);
        $csrf = $this->security->issueCsrfToken();
        $tokenField = $local ? '' : <<<'HTML'
<label>Installation token<input name="install_token" type="password" autocomplete="off" required></label>
HTML;
        $html = $this->page('Install VertoAD', <<<HTML
<main>
  <header><span>VertoAD</span><h1>Secure installation</h1><p>Initialize the database and first platform administrator.</p></header>
  <form method="post" action="/install" autocomplete="off">
    <input type="hidden" name="_csrf" value="{$this->escape($csrf)}">
    <fieldset><legend>Database</legend>
      <div class="grid"><label>Host<input name="db_host" value="127.0.0.1" required></label><label>Port<input name="db_port" type="number" min="1" max="65535" value="3306" required></label></div>
      <label>Database name<input name="db_name" value="vertoad" required></label>
      <div class="grid"><label>Username<input name="db_username" required></label><label>Password<input name="db_password" type="password" autocomplete="new-password" required></label></div>
    </fieldset>
    <fieldset><legend>Administrator</legend>
      <div class="grid"><label>Email<input name="admin_email" type="email" required></label><label>Display name<input name="admin_display_name" required></label></div>
      <div class="grid"><label>Password<input name="admin_password" type="password" minlength="14" autocomplete="new-password" required></label><label>Confirm password<input name="admin_password_confirmation" type="password" minlength="14" autocomplete="new-password" required></label></div>
      <div class="grid"><label>Organization<input name="organization_name" value="VertoAD Admin" required></label><label>Organization slug<input name="organization_slug" value="vertoad-admin" required></label></div>
    </fieldset>
    <fieldset><legend>Public endpoints</legend>
      <label>Application URL<input name="app_url" type="url" value="http://localhost:5173" required></label>
      <label>API URL<input name="api_url" type="url" value="http://localhost:8080" required></label>
      <div class="grid"><label>SDK base URL<input name="sdk_public_base_url" type="url" value="http://localhost:5173" required></label><label>Ads base URL<input name="ads_public_base_url" type="url" value="http://localhost:8080" required></label></div>
      <label>OAuth redirect URI <small>Optional; defaults to the application callback.</small><input name="oauth_redirect_uri" type="url"></label>
    </fieldset>
    {$tokenField}
    <button type="submit">Install VertoAD</button>
  </form>
</main>
HTML);
        $response->getBody()->write($html);

        return $this->security->withCsrfCookie($this->secure($response)->withHeader('Content-Type', 'text/html; charset=utf-8'), $csrf, $request);
    }

    private function install(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Installation request body must be an object or form payload.');
        }

        $local = $this->security->assertCanInstall($request, $body);
        $input = InstallInput::fromArray($body, $local);
        $clientIp = $this->clientIpResolver->resolve($request);
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        $result = $this->installer->install(
            $input,
            $local,
            $clientIp,
            $userAgent === '' ? null : mb_substr($userAgent, 0, 512, 'UTF-8'),
            RequestIdContext::ensure($request),
        );

        if ($this->wantsJson($request)) {
            $response->getBody()->write(json_encode([
                'installed' => true,
                'installation_id' => $result['installation_id'],
                'admin_user_id' => $result['admin_user_id'],
                'organization_id' => $result['organization_id'],
                'oauth_client_id' => $result['oauth_client_id'],
                'oauth_client_type' => 'public',
                'oauth_pkce_required' => true,
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
            $response = $response->withHeader('Content-Type', 'application/json');
        } else {
            $response->getBody()->write($this->page('VertoAD installed', sprintf(
                '<main><header><span>VertoAD</span><h1>Installation complete</h1><p>The installer is now permanently disabled.</p></header><section class="result"><p>First-party SPA OAuth client ID</p><code>%s</code><strong>Public client: configure this ID in the SPA and use Authorization Code + S256 PKCE. No client secret is issued.</strong></section></main>',
                $this->escape($result['oauth_client_id']),
            )));
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        return $this->security->withCsrfCookie($this->secure($response->withStatus(201)), '', $request, clear: true);
    }

    private function error(ServerRequestInterface $request, ResponseInterface $response, int $status, string $code, string $message): ResponseInterface
    {
        if ($this->wantsJson($request)) {
            $response->getBody()->write(json_encode([
                'installed' => false,
                'error' => ['code' => $code, 'message' => $message],
                'request_id' => RequestIdContext::ensure($request),
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
            $response = $response->withHeader('Content-Type', 'application/json');
        } else {
            $response->getBody()->write($this->page('Installation unavailable', sprintf(
                '<main><header><span>VertoAD</span><h1>Installation unavailable</h1><p>%s</p></header><a href="/install">Return to installer</a></main>',
                $this->escape($message),
            )));
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        return $this->secure($response->withStatus($status));
    }

    private function secure(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        return str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->escape($title) . '</title><style>'
            . ':root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#16181d;background:#f5f6f8}*{box-sizing:border-box}body{margin:0}main{width:min(760px,calc(100% - 32px));margin:48px auto 80px}header{border-bottom:1px solid #d9dde5;padding-bottom:24px;margin-bottom:28px}header span{color:#0f766e;font-weight:700}h1{font-size:28px;margin:8px 0}p{color:#596170;line-height:1.5}form{display:grid;gap:24px}fieldset{border:0;border-top:1px solid #d9dde5;padding:20px 0 0;margin:0}legend{font-weight:700;padding:0 12px 0 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{display:grid;gap:7px;margin:0 0 14px;font-size:14px;font-weight:600}small{font-weight:400;color:#6b7280}input{width:100%;height:42px;border:1px solid #c8ced8;border-radius:6px;background:#fff;padding:0 12px;font:inherit}input:focus{outline:3px solid #99f6e4;border-color:#0f766e}button{height:44px;border:0;border-radius:6px;background:#0f766e;color:#fff;font-weight:700;cursor:pointer}.result{border-left:3px solid #0f766e;padding:4px 0 4px 20px}.result code{display:block;overflow-wrap:anywhere;background:#fff;border:1px solid #d9dde5;padding:10px;border-radius:4px}.result strong{display:block;margin-top:20px;color:#b42318}a{color:#0f766e}@media(max-width:620px){main{margin-top:24px}.grid{grid-template-columns:1fr}}'
            . '</style></head><body>' . $body . '</body></html>';
    }
}
