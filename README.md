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
