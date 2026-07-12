<?php

declare(strict_types=1);

use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
EnvironmentLoader::load($root);
$settings = require $root . '/config/settings.php';
$databaseName = getenv('TEST_DB_DATABASE') ?: ((string) $settings['database']['database'] . '_test');
if (!str_contains(strtolower($databaseName), 'test')) {
    throw new RuntimeException('Webhook outbox rehearsal is restricted to a database whose name contains test.');
}

$database = $settings['database'];
$database['database'] = $databaseName;
$connection = (new ConnectionFactory())->create($database);
$suffix = bin2hex(random_bytes(6));
$requestId = 'req_webhook_rehearsal_' . $suffix;
$result = [];

$connection->beginTransaction();
try {
    $connection->executeStatement('SET @vertoad_request_id = ?', [$requestId]);
    $connection->insert('users', [
        'email' => 'webhook-' . $suffix . '@example.test',
        'password_hash' => password_hash('not-a-real-secret', PASSWORD_DEFAULT),
        'display_name' => 'Webhook rehearsal',
    ]);
    $userId = (int) $connection->lastInsertId();
    $connection->insert('organizations', [
        'name' => 'Webhook rehearsal ' . $suffix,
        'slug' => 'webhook-rehearsal-' . $suffix,
    ]);
    $organizationId = (int) $connection->lastInsertId();

    $connection->insert('ledger_entries', [
        'organization_id' => $organizationId,
        'account_type' => 'advertiser_balance',
        'account_id' => null,
        'points_amount' => 500,
        'direction' => 'credit',
        'balance_after_points' => 500,
        'reference_type' => 'rehearsal',
        'reference_id' => null,
        'idempotency_key' => 'webhook-rehearsal-credit-' . $suffix,
        'memo' => 'Webhook rehearsal credit',
        'metadata_json' => json_encode(['entry_kind' => 'rehearsal'], JSON_THROW_ON_ERROR),
    ]);
    $creditLedgerId = (int) $connection->lastInsertId();

    $connection->insert('campaigns', [
        'organization_id' => $organizationId,
        'name' => 'Webhook campaign',
        'status' => 'draft',
        'pricing_model' => 'cpm',
        'bid_points' => 10,
        'landing_url' => 'https://example.test/landing',
        'targeting_json' => '{}',
    ]);
    $campaignId = (int) $connection->lastInsertId();
    $connection->update('campaigns', ['status' => 'paused', 'pause_reason' => 'rehearsal'], ['id' => $campaignId]);
    $connection->update('campaigns', ['name' => 'Webhook campaign renamed'], ['id' => $campaignId]);

    $connection->insert('attribution_conversions', [
        'event_id' => 'server_api:' . $organizationId . ':rehearsal-' . $suffix,
        'conversion_id' => 'conversion_rehearsal_' . $suffix,
        'organization_id' => $organizationId,
        'oauth_client_id' => null,
        'recorded_by_user_id' => $userId,
        'attributed' => 0,
        'click_event_id' => null,
        'decision_id' => null,
        'campaign_id' => $campaignId,
        'window_seconds' => 604800,
        'source' => 'server_api',
        'conversion_name' => 'purchase',
        'value_points' => 1200,
        'occurred_at' => '2026-07-12 08:00:00',
    ]);
    $connection->insert('attribution_conversions', [
        'event_id' => 'browser_pixel:rehearsal-' . $suffix,
        'conversion_id' => 'conversion_browser_rehearsal_' . $suffix,
        'organization_id' => null,
        'oauth_client_id' => null,
        'recorded_by_user_id' => null,
        'attributed' => 1,
        'click_event_id' => null,
        'decision_id' => null,
        'campaign_id' => $campaignId,
        'window_seconds' => 604800,
        'source' => 'browser_pixel',
        'conversion_name' => 'signup',
        'value_points' => 0,
        'occurred_at' => '2026-07-12 08:01:00',
    ]);

    $connection->insert('oauth_clients', [
        'organization_id' => $organizationId,
        'owner_user_id' => $userId,
        'client_identifier' => 'vocs_rehearsal_' . $suffix,
        'name' => 'Webhook API client',
        'secret_hash' => password_hash('client-secret', PASSWORD_DEFAULT),
        'redirect_uris_json' => '["https://example.test/callback"]',
        'grant_types_json' => '["client_credentials"]',
        'scopes_json' => '["campaign.read"]',
        'is_confidential' => 1,
    ]);
    $oauthClientId = (int) $connection->lastInsertId();
    foreach (['sdk.oauth_client.create', 'sdk.oauth_client.rotate_secret'] as $action) {
        $connection->insert('audit_logs', [
            'organization_id' => $organizationId,
            'actor_user_id' => $userId,
            'action' => $action,
            'subject_type' => 'oauth_client',
            'subject_id' => $oauthClientId,
            'request_id' => $requestId,
            'metadata_json' => json_encode([
                'client_id' => 'vocs_rehearsal_' . $suffix,
                'name' => 'Webhook API client',
                'is_confidential' => true,
                'grant_types' => ['client_credentials'],
                'scopes' => ['campaign.read'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $connection->insert('asset_upload_intents', [
        'organization_id' => $organizationId,
        'uploader_user_id' => $userId,
        'type' => 'image',
        'original_filename' => 'rehearsal.png',
        'object_key' => 'rehearsal/' . $suffix . '/asset.png',
        'content_type' => 'image/png',
        'byte_size' => 100,
        'status' => 'confirmed',
        'expires_at' => '2026-07-13 08:00:00',
    ]);
    $uploadIntentId = (int) $connection->lastInsertId();
    $connection->insert('creative_assets', [
        'upload_intent_id' => $uploadIntentId,
        'organization_id' => $organizationId,
        'uploader_user_id' => $userId,
        'type' => 'image',
        'object_key' => 'rehearsal/' . $suffix . '/asset.png',
        'content_type' => 'image/png',
        'byte_size' => 100,
        'width' => 10,
        'height' => 10,
        'status' => 'pending_review',
    ]);
    $assetId = (int) $connection->lastInsertId();
    $connection->insert('creative_reviews', [
        'asset_id' => $assetId,
        'organization_id' => $organizationId,
        'status' => 'needs_human',
        'ai_risk_labels' => '[]',
        'ai_reasons' => '[]',
        'requested_by_user_id' => $userId,
    ]);
    $reviewId = (int) $connection->lastInsertId();
    $connection->insert('review_decisions', [
        'review_id' => $reviewId,
        'asset_id' => $assetId,
        'organization_id' => $organizationId,
        'actor_user_id' => $userId,
        'decision' => 'approved',
        'reason' => 'Rehearsal approval',
        'from_status' => 'needs_human',
        'to_status' => 'approved',
    ]);

    $connection->insert('asset_upload_intents', [
        'organization_id' => $organizationId,
        'uploader_user_id' => $userId,
        'type' => 'image',
        'original_filename' => 'rehearsal-rejected.png',
        'object_key' => 'rehearsal/' . $suffix . '/asset-rejected.png',
        'content_type' => 'image/png',
        'byte_size' => 100,
        'status' => 'confirmed',
        'expires_at' => '2026-07-13 08:00:00',
    ]);
    $rejectedUploadIntentId = (int) $connection->lastInsertId();
    $connection->insert('creative_assets', [
        'upload_intent_id' => $rejectedUploadIntentId,
        'organization_id' => $organizationId,
        'uploader_user_id' => $userId,
        'type' => 'image',
        'object_key' => 'rehearsal/' . $suffix . '/asset-rejected.png',
        'content_type' => 'image/png',
        'byte_size' => 100,
        'width' => 10,
        'height' => 10,
        'status' => 'pending_review',
    ]);
    $rejectedAssetId = (int) $connection->lastInsertId();
    $connection->insert('creative_reviews', [
        'asset_id' => $rejectedAssetId,
        'organization_id' => $organizationId,
        'status' => 'needs_human',
        'ai_risk_labels' => '[]',
        'ai_reasons' => '[]',
        'requested_by_user_id' => $userId,
    ]);
    $rejectedReviewId = (int) $connection->lastInsertId();
    $connection->insert('review_decisions', [
        'review_id' => $rejectedReviewId,
        'asset_id' => $rejectedAssetId,
        'organization_id' => $organizationId,
        'actor_user_id' => $userId,
        'decision' => 'rejected',
        'reason' => 'Rehearsal rejection',
        'from_status' => 'needs_human',
        'to_status' => 'rejected',
    ]);

    $connection->insert('ledger_entries', [
        'organization_id' => $organizationId,
        'account_type' => 'publisher_earnings',
        'account_id' => null,
        'points_amount' => 100,
        'direction' => 'debit',
        'balance_after_points' => 400,
        'reference_type' => 'withdrawal_request',
        'reference_id' => null,
        'idempotency_key' => 'webhook-rehearsal-withdrawal-' . $suffix,
        'memo' => 'Webhook rehearsal withdrawal',
        'metadata_json' => '{}',
    ]);
    $withdrawalLedgerId = (int) $connection->lastInsertId();
    $connection->insert('withdrawal_requests', [
        'organization_id' => $organizationId,
        'requested_by_user_id' => $userId,
        'points_amount' => 100,
        'amount_cny' => '1.00',
        'points_per_cny' => 100,
        'idempotency_key' => 'webhook-rehearsal-' . $suffix,
        'review_status' => 'pending',
        'payment_status' => 'not_started',
        'payout_method' => 'rehearsal',
        'payout_account_json' => '{"account":"redacted"}',
        'ledger_entry_id' => $withdrawalLedgerId,
        'requested_at' => '2026-07-12 08:00:00',
    ]);
    $withdrawalId = (int) $connection->lastInsertId();
    $connection->insert('withdrawal_audit_events', [
        'withdrawal_request_id' => $withdrawalId,
        'organization_id' => $organizationId,
        'actor_user_id' => $userId,
        'action' => 'requested',
        'from_review_status' => null,
        'to_review_status' => 'pending',
        'from_payment_status' => null,
        'to_payment_status' => 'not_started',
        'proof_id' => null,
        'notes' => null,
        'metadata_json' => null,
    ]);

    $rows = $connection->fetchAllAssociative(
        'SELECT event_id, event_type, api_version, request_id, data_json FROM webhook_outbox_events WHERE request_id = ? ORDER BY id',
        [$requestId],
    );
    $eventTypes = array_values(array_unique(array_column($rows, 'event_type')));
    sort($eventTypes);
    $expectedTypes = [
        'api_client.created',
        'api_client.secret_rotated',
        'billing.points_changed',
        'campaign.status_changed',
        'conversion.received',
        'review.approved',
        'review.rejected',
        'withdrawal.status_changed',
    ];
    if ($eventTypes !== $expectedTypes) {
        throw new RuntimeException('Unexpected webhook event types: ' . json_encode($eventTypes));
    }
    if (count(array_unique(array_column($rows, 'event_id'))) !== count($rows)) {
        throw new RuntimeException('Webhook event IDs were not unique.');
    }
    if (count($rows) !== 11) {
        throw new RuntimeException('Unexpected webhook outbox event count: ' . count($rows));
    }
    $conversionEventCount = count(array_filter(
        $rows,
        static fn (array $row): bool => $row['event_type'] === 'conversion.received',
    ));
    if ($conversionEventCount !== 2) {
        throw new RuntimeException('Both direct and organization-resolved conversion events must be captured.');
    }
    if (array_values(array_unique(array_column($rows, 'api_version'))) !== ['2026-07-12']) {
        throw new RuntimeException('Webhook API versions were not persisted with their outbox events.');
    }
    foreach ($rows as $row) {
        $data = json_decode((string) $row['data_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || $data === []) {
            throw new RuntimeException('Webhook outbox data was not a non-empty JSON object.');
        }
    }

    $result = [
        'database' => $databaseName,
        'event_count' => count($rows),
        'event_types' => $eventTypes,
        'conversion_events' => $conversionEventCount,
        'api_version' => '2026-07-12',
        'request_id_preserved' => count(array_filter(
            $rows,
            static fn (array $row): bool => $row['request_id'] === $requestId,
        )) === count($rows),
        'credit_ledger_id' => $creditLedgerId,
    ];
} finally {
    $connection->rollBack();
    $connection->executeStatement('SET @vertoad_request_id = NULL');
}

$remaining = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM webhook_outbox_events WHERE request_id = ?',
    [$requestId],
);
if ($remaining !== 0) {
    throw new RuntimeException('Webhook rehearsal rollback left outbox rows behind.');
}
$result['rollback_clean'] = true;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
