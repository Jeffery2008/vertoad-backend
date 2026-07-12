# VertoAD Webhooks

VertoAD delivers organization-scoped business events to active HTTPS endpoints. Delivery is at least once. Each event has a stable `id`; receivers must persist that ID and make processing idempotent.

## Event types

| Event | Emitted after |
| --- | --- |
| `review.approved` | A human reviewer commits an approved final decision. |
| `review.rejected` | A human reviewer commits a rejected final decision. |
| `billing.points_changed` | An append-only ledger credit or debit commits. |
| `campaign.status_changed` | A campaign is created or its serving status changes, including automatic budget pauses. |
| `conversion.received` | A server API conversion, or an organization-resolvable browser conversion, commits. |
| `withdrawal.status_changed` | A withdrawal is requested, approved, paid, rejected, revoked, or resubmitted. Proof-only events are not emitted as status changes. |
| `api_client.created` | An organization OAuth API client and its audit record commit. |
| `api_client.secret_rotated` | A confidential API client secret rotation and its audit record commit. The secret is never included. |
| `webhook.test` | The endpoint test API queues a synthetic event. |

Endpoint event names remain extensible for other documented platform events. The list above is the business event set wired through the transactional outbox.

## Payload

Every delivery uses the same envelope:

```json
{
  "id": "evt_review_decision_842",
  "type": "review.approved",
  "api_version": "2026-07-12",
  "created_at": "2026-07-12T08:30:15.123456Z",
  "organization_id": 99,
  "request_id": "req_0123456789abcdef",
  "data": {
    "review_id": 217,
    "asset_id": 501,
    "decision": "approved",
    "reason": "Policy checks passed",
    "from_status": "needs_human",
    "to_status": "approved",
    "decided_by_user_id": 7
  }
}
```

`request_id` is nullable for business changes without an originating API or Cron request. `api_version` is persisted with the outbox fact, so a queued event keeps the contract version under which it was created even when deployment happens before fan-out. Event-specific `data` schemas are defined in `docs/openapi.yaml` under `WebhookEventEnvelope`.

## Signature verification

VertoAD sends:

```text
X-VertoAD-Signature: t=1783825815,v1=<64 lowercase hex characters>
```

`v1` is:

```text
HMAC-SHA256(endpoint_signing_secret, timestamp + "." + raw_request_body)
```

Verify against the exact request bytes before JSON parsing. Compare signatures with a constant-time function. Receivers should also reject timestamps outside their replay tolerance.

PHP example:

```php
$header = $_SERVER['HTTP_X_VERTOAD_SIGNATURE'] ?? '';
$rawBody = file_get_contents('php://input');

if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $matches) !== 1) {
    http_response_code(400);
    exit;
}

$expected = hash_hmac('sha256', $matches[1] . '.' . $rawBody, $signingSecret);
if (!hash_equals($expected, $matches[2])) {
    http_response_code(401);
    exit;
}
```

Signing secrets are returned only when an endpoint is created or rotated. Secret rotation applies to subsequent attempts, including retries of previously queued events.

## Delivery and retries

The protected `webhook-retry` Cron job performs two stages under its distributed lock:

1. Lease pending outbox events and atomically create one deterministic delivery per active matching endpoint.
2. Send due `queued` or `failed` deliveries using the endpoint secret and configured exponential retry policy.

Any HTTP `2xx` response marks an attempt delivered. Network failures, timeouts, and non-`2xx` responses are recorded and retried. The current retry cap, batch size, HTTP timeout, and base backoff come from the versioned `webhook.delivery_policy` system configuration. Exhausted deliveries remain queryable and can be manually retried through the protected Operations API.

An endpoint may receive the same event more than once if a process stops after the remote server accepts the request but before VertoAD persists the response. Deduplicate by envelope `id`, not by delivery time or signature timestamp. Ordering across endpoints and event types is not guaranteed.

## Transaction guarantees

Business writes and their outbox rows commit in the same MySQL transaction. The outbox is populated from durable facts:

- final review decisions;
- append-only ledger entries;
- campaign inserts and status transitions;
- conversion inserts;
- withdrawal status audit events;
- OAuth client audit events.

If outbox insertion fails, the originating business transaction fails. If fan-out fails, the outbox event remains durable with retry state. Fan-out and the outbox `dispatched` transition share one transaction, and delivery IDs are derived from the endpoint and event IDs, so lease recovery cannot create duplicate delivery rows.

The outbox lease has no terminal attempt cap. Expired processing leases are reclaimed after interrupted workers; local fan-out failures use bounded exponential backoff. HTTP delivery retains the existing configured retry cap and failure log contract.

## MySQL 8.4 Deployment And Trigger Gate

The business outbox is installed by
`db/migrations/20260712090000_create_webhook_business_outbox.php`. The migration
creates the durable outbox table and these seven MySQL `AFTER` triggers:

| Trigger | Source table | Event |
| --- | --- | --- |
| `trg_webhook_review_decision_outbox` | `review_decisions` | `review.approved` or `review.rejected` |
| `trg_webhook_ledger_entry_outbox` | `ledger_entries` | `billing.points_changed` |
| `trg_webhook_campaign_created_outbox` | `campaigns` | `campaign.status_changed` for creation |
| `trg_webhook_campaign_status_outbox` | `campaigns` | `campaign.status_changed` for a status change |
| `trg_webhook_conversion_outbox` | `attribution_conversions` | `conversion.received` |
| `trg_webhook_withdrawal_status_outbox` | `withdrawal_audit_events` | `withdrawal.status_changed` for supported status actions |
| `trg_webhook_api_client_outbox` | `audit_logs` | `api_client.created` or `api_client.secret_rotated` |

VertoAD supports MySQL 8.4 for this migration. Run Phinx with the external
application environment and a non-root migration account. That account must
have the schema DDL/DML privileges required by all migrations, including the
`TRIGGER` privilege. It must not receive `SUPER` just to create these triggers.

If binary logging is enabled, a MySQL administrator must enable trusted
function creators. MySQL applies this binary-logging restriction to trigger
creation as well as stored functions. Check and persist the setting outside the
application account:

```sql
SELECT VERSION(), @@GLOBAL.log_bin, @@GLOBAL.log_bin_trust_function_creators;
SET PERSIST log_bin_trust_function_creators = ON;
```

The migration account should then be able to run `composer run migrate` without
privilege escalation. Point `VERTOAD_ENV_FILE` at the external absolute runtime
file and set `PHINX_ENVIRONMENT=production` so the command cannot silently use
the default development environment. Afterward, confirm the migration state
and trigger set:

```powershell
$env:VERTOAD_ENV_FILE = 'C:\vertoad\env\staging.env'
$env:PHINX_ENVIRONMENT = 'production'
composer run migrate
vendor/bin/phinx status -e production
```

```sql
SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME LIKE 'trg_webhook_%'
ORDER BY TRIGGER_NAME;
```

The query must return exactly the seven trigger names listed above. Trigger
definers are persisted by MySQL; rotating a password is safe, but removing or
renaming a definer account requires an explicit trigger recreation plan.

For a disposable, fully migrated database whose name contains `test`, run the
transactional trigger rehearsal:

```powershell
composer run test:mysql-webhook-outbox-rehearsal
```

The command inserts representative rows for every wired business source,
checks 11 outbox events across the eight expected event types, verifies that
the session request ID is preserved on every row, and rolls the transaction
back. Passing output must include `request_id_preserved: true` and
`rollback_clean: true`. It must never run against staging or production.

## Endpoint behavior

- Only active endpoints subscribed to the event type receive business events.
- Paused endpoints are excluded when outbox fan-out occurs.
- The test endpoint can queue `webhook.test` even when it is not listed in subscriptions.
- Endpoint URLs must use HTTPS and cannot target localhost, private, reserved, or link-local IP literals.
- Delivery history is scoped to the endpoint organization and includes request ID, status, attempt count, response status, last error, signature, and timestamps.
