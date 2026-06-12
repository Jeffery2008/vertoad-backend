# VertoAD Backend

Slim 4 API backend for VertoAD.

## Setup

```powershell
cd backend
composer install
Copy-Item .env.example .env
```

Update `.env` with local database, Redis, OAuth, object storage, Turnstile, cron, Cloudflare, and AI review settings before running the API.

Generate `APP_KEY` as a Defuse ASCII-safe key before using recharge key encryption:

```powershell
php -r "require 'vendor/autoload.php'; echo Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString(), PHP_EOL;"
```

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
composer test:coverage
composer test:redis-integration
```

The PHPUnit suite uses `phpunit.xml` and boots from `vendor/autoload.php`.

`composer test` and `composer test:coverage` exclude only the explicit external `redis-integration` group and fail if any default-suite test is skipped. `composer test:coverage` runs `scripts/coverage-gate.php`. When Xdebug, PCOV, or a working phpdbg coverage driver is available, it generates Clover coverage for `src/` and requires 100% line coverage. If no working coverage driver is available, the script prints an explicit blocker with the detected SAPI, coverage extensions, and phpdbg status, then still runs the default PHPUnit suite so this environment remains test-gated. Install or enable Xdebug with `XDEBUG_MODE=coverage`, PCOV, or phpdbg to make the coverage gate enforce line coverage locally. No `src/` files are excluded from the configured coverage source.

Redis serving event buffering has an explicit real Redis integration harness outside the default suite so local and CI runs without external Redis remain deterministic. To exercise the production Redis path, run Redis 8.8 with a strong password, then opt in:

```powershell
$redisPassword = "replace-with-32-plus-character-random-password"
docker run --rm --name vertoad-redis-88 -p 6379:6379 index.docker.io/library/redis:8.8.0 redis-server --requirepass $redisPassword --rename-command FLUSHALL "" --rename-command FLUSHDB "" --rename-command CONFIG ""

$env:VERTOAD_REDIS_INTEGRATION = "1"
$env:REDIS_HOST = "127.0.0.1"
$env:REDIS_PORT = "6379"
$env:REDIS_PASSWORD = $redisPassword
composer test:redis-integration
```

For a local Redis 8.8 container, use a throwaway password and disable dangerous commands in the config used by the container. Production Redis is password-only in the current deployment assumption, so the password must be long random material, dangerous commands must remain disabled where the managed service allows it, the Redis key prefix must be environment-specific, and failed auth/connectivity alerts must be monitored.

The frontend coverage gate is run from the frontend workspace:

```powershell
cd ../frontend
pnpm test:coverage
```

## OpenAPI

```powershell
composer openapi:check
```

The OpenAPI contract lives at `docs/openapi.yaml`. When the PHP yaml extension is installed, the check command parses the full YAML document. Without that extension, it uses a structural fallback that still verifies the OpenAPI version, envelope schemas, implemented `/api/v1` route coverage, route security annotations, frontend-used query parameters, duplicate path keys, duplicate tags, and stale placeholder metadata.

The current public contract intentionally documents only implemented endpoints. Its implemented groups are:

- Auth: first-party registration, login, logout, password reset, and authenticated current-user context.
- Health: runtime liveness metadata.
- Permissions: public permission inventory metadata for frontend route gating and developer tooling.
- Billing: authenticated advertiser points balance, ledger, recharge-key redemption, admin recharge-key generation/plaintext reveal, publisher withdrawals, and withdrawal proof upload confirmation endpoints.
- Assets, Campaigns, and Review: authenticated creative upload validation, campaign lifecycle, and AI/human review endpoints.
- Publisher: site verification, ad slot presets, and slot management endpoints.
- Reports, Attribution, Archive, and Serving: dashboard reporting, conversion attribution, cold-data jobs, public serve/track/click delivery endpoints, and SDK-facing ad event contracts.
- OAuth: client self-service, authorization consent, token exchange, and token revocation.
- Operations, Webhooks, Support, FeatureFlags, and Cron: operational logs/config/webhook controls, support tickets, rollout evaluation, and protected maintenance job endpoints.

Do not add new public endpoints to OpenAPI until the corresponding backend route, response shape, security behavior, and tests exist. When a frontend client starts using a query parameter or request field, update both `docs/openapi.yaml` and `scripts/openapi-check.php` so the contract gate can prevent drift.

## Backend Boundaries

The current backend slice establishes the Slim application shell, environment-backed settings, health checks, protected Cron API surface, first-party auth/session bridge endpoints, permission metadata, and the implemented authenticated billing endpoints documented in OpenAPI.

System configuration is currently loaded from `.env` into PHP settings for infrastructure integrations such as MySQL, Redis, S3-compatible storage, OAuth key paths, cron protection, Cloudflare real IP handling, Turnstile, and AI review. The product roadmap calls for business configuration to move into versioned, auditable admin-managed records; until that storage and API surface exists, docs should treat `.env` settings as runtime infrastructure config only.

Creative upload limits are business configuration, not deployment variables. Upload intent TTL, blocked extensions/content types, per-type byte limits, dimensions, video duration, allowed content types, and magic signatures come from the current `system_config_versions.assets.upload_policy`. `.env` keeps only S3/R2 connection details and secrets for object storage, never upload policy rules.

Cloudflare real-IP handling is fail-closed. `CLOUDFLARE_REAL_IP_HEADER` defaults to `CF-Connecting-IP`, but the header is trusted only when `CLOUDFLARE_TRUSTED_PROXIES` contains the connecting proxy IP or CIDR. If the trusted proxy list is empty or does not match `REMOTE_ADDR`, the API ignores forwarded IP headers and uses `REMOTE_ADDR` for rate limits, Turnstile audit metadata, Cron IP checks, password reset logs, and billing admin audit logs.

AI creative review is fail-closed outside local/test environments. `local`, `test`, and `testing` may use the deterministic provider so unit tests and isolated development remain stable. In `prod`/`staging`, `AI_REVIEW_BASE_URL`, `AI_REVIEW_API_KEY`, and `AI_REVIEW_MODEL` are required; missing or blank values are a startup/configuration failure instead of a deterministic approval fallback. `AI_REVIEW_PROMPT`, timeout, token limits, and temperature should be set per environment and kept in the deployment secret/config store, not committed.

Serving events and serving frequency caps are Redis-first outside local/test environments. `serve`, `track`, and `click` event writes go to Redis, and the protected Cron job `redis-events-consume` leases events, persists them to MySQL, bills valid events, and only acknowledges the Redis lease after persistence and billing processing complete. A MySQL persistence failure must leave the event unacknowledged so the Redis visibility timeout can redeliver it. Frequency caps use `REDIS_PREFIX`-scoped hashed viewer buckets for campaign + ad slot hourly/daily serve counts, with local/test memory fallback only.

Production must not fall back to DB-first serving event buffering or in-memory serving frequency caps. In `prod`/`staging`, missing Redis extension or missing `REDIS_PASSWORD` is a startup/configuration failure for the serving event buffer, frequency caps, cron locks, rate limits, and config cache refresh. Local and test environments may use in-memory fallback to keep unit tests independent from Redis.

Redis serving settings:

- `REDIS_PASSWORD`: required for production serving event buffering and frequency caps; use 32+ random characters at minimum.
- `REDIS_PREFIX`: use an environment-specific prefix such as `vertoad:prod:` or `vertoad:staging:` to avoid shared Redis collisions.
- `REDIS_SERVING_EVENT_VISIBILITY_TIMEOUT_SECONDS`: time before a leased but unacknowledged event becomes eligible for redelivery.
- `REDIS_SERVING_EVENT_RETENTION_SECONDS`: payload and dedupe retention window.
- `REDIS_DANGEROUS_COMMANDS_DISABLED`: comma-separated dangerous Redis commands disabled by the managed service, for example `FLUSHALL,FLUSHDB,CONFIG`.
- `REDIS_AUTH_FAILURE_ALERTING_CONFIGURED`: set to `true` only after failed-auth/connectivity alerting is configured for the managed Redis service.
- `CRON_EVENT_CONSUME_BATCH_SIZE`: max events consumed per Cron run.
- `CRON_LOCK_TTL_SECONDS`: Redis lock TTL used to prevent concurrent Cron runs.

Recommended targeted checks before deploying Redis/Cron changes:

```powershell
vendor\bin\phpunit tests\Serving
vendor\bin\phpunit tests\Cron
composer test:coverage
composer test:redis-integration
composer openapi:check
```

Audit logging is a required platform boundary for sensitive operations, configuration changes, ledger adjustments, cron operations, and support/admin actions. It is not currently exposed as a public API in this slice, so contracts should describe audit behavior only when the backing implementation is present.

The points ledger is a core platform boundary. The current implemented HTTP surface includes authenticated advertiser balance, ledger listing, recharge-key redemption, admin recharge-key generation/plaintext reveal, and publisher withdrawal flows. Broader ledger operations for publisher earnings, adjustments, and reversals should remain out of OpenAPI until routes, persistence, and tests are implemented.

Near-term backend contract work should keep OpenAPI aligned with implemented routes, then add OAuth2-protected resources, audit records, and broader ledger operations as those slices land.

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
