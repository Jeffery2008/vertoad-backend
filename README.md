# VertoAD Backend

Slim 4 API backend for VertoAD.

## Setup

```powershell
cd backend
composer install
Copy-Item .env.example .env
```

Update `.env` with local database, Redis, OAuth, object storage, Turnstile, cron, Cloudflare, and AI review settings before running the API.

## Development Server

```powershell
composer serve
```

This runs the Slim app through PHP's built-in server at `http://127.0.0.1:8080` with `public/` as the document root.

## Database Migrations

```powershell
composer migrate
composer migrate:rollback
```

Phinx reads `phinx.php` and environment values from `.env` when present. Set `PHINX_ENVIRONMENT=testing` to target the configured test database.

## Tests

```powershell
composer test
```

The PHPUnit suite uses `phpunit.xml` and boots from `vendor/autoload.php`.

## OpenAPI

```powershell
composer openapi:check
```

The OpenAPI contract lives at `docs/openapi.yaml`. The check command requires the PHP yaml extension because it uses `yaml_parse_file`.

The current public contract intentionally documents only implemented endpoints:

- `GET /api/v1/health`
- `GET /api/v1/cron/status`

Do not add public advertiser, publisher, ledger, reporting, OAuth, or admin endpoints to OpenAPI until the corresponding backend route and response shape exist.

## Backend Boundaries

The current backend slice establishes the Slim application shell, environment-backed settings, health checks, and the protected Cron API surface.

System configuration is currently loaded from `.env` into PHP settings for infrastructure integrations such as MySQL, Redis, S3-compatible storage, OAuth key paths, cron protection, Cloudflare real IP handling, Turnstile, and AI review. The product roadmap calls for business configuration to move into versioned, auditable admin-managed records; until that storage and API surface exists, docs should treat `.env` settings as runtime infrastructure config only.

Audit logging is a required platform boundary for sensitive operations, configuration changes, ledger adjustments, cron operations, and support/admin actions. It is not currently exposed as a public API in this slice, so contracts should describe audit behavior only when the backing implementation is present.

The points ledger is a core planned boundary for advertiser balances, publisher earnings, adjustments, reversals, recharge keys, and withdrawals. Public ledger APIs are not part of the current implemented HTTP surface and should remain out of OpenAPI until routes, persistence, and tests are implemented.

Near-term backend contract work should keep OpenAPI aligned with implemented routes, then add authenticated envelopes, OAuth2-protected resources, audit records, and ledger operations as those slices land.

## Healthcheck

```powershell
powershell -ExecutionPolicy Bypass -File scripts/healthcheck.ps1
```

Override the default health URL with either a parameter or environment variable:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/healthcheck.ps1 -Url "https://api.example.com/api/v1/health"
$env:VERTOAD_HEALTH_URL = "https://api.example.com/api/v1/health"
```

## Windows Server 2012 Apache Note

When deploying behind Apache on Windows Server 2012, configure the virtual host `DocumentRoot` to the backend `public/` directory, not the repository root. This keeps application source, configuration, migrations, and vendor files outside the web-accessible path.
