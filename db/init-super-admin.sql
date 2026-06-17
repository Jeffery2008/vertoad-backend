-- Placeholder bootstrap script for the first VertoAD super admin.
-- Run this only against a freshly migrated database, then rotate/remove it from
-- deployment automation. Replace all example values before execution.
--
-- Password handling:
--   Generate password_hash with PHP's password_hash($plainText, PASSWORD_DEFAULT)
--   and never store or commit the plain text password.

START TRANSACTION;

INSERT INTO users (email, password_hash, display_name, status, email_verified_at)
VALUES (
    'admin@example.com',
    '$2y$12$replace_with_password_hash_from_password_hash',
    'Initial Super Admin',
    'active',
    CURRENT_TIMESTAMP
);

SET @super_admin_user_id = LAST_INSERT_ID();

INSERT INTO organizations (name, slug, billing_status)
VALUES ('VertoAD Admin', 'vertoad-admin', 'active');

SET @admin_organization_id = LAST_INSERT_ID();

INSERT INTO organization_members (organization_id, user_id, status, title)
VALUES (@admin_organization_id, @super_admin_user_id, 'active', 'Super Admin');

INSERT INTO roles (organization_id, name, slug, description, is_system)
VALUES (NULL, 'Super Admin', 'super-admin', 'Unrestricted platform administration role.', 1);

SET @super_admin_role_id = LAST_INSERT_ID();

INSERT INTO permissions (slug, description)
VALUES
    ('org.read.own', 'View own organization details.'),
    ('org.read.platform', 'View organization details across the platform.'),
    ('org.write.own', 'Update own organization details.'),
    ('org.write.platform', 'Update organization details across the platform.'),
    ('org.status.update.platform', 'Suspend or restore organizations across the platform.'),
    ('organizations.members.read', 'Read organization membership and role assignments.'),
    ('organizations.members.manage', 'Manage organization membership and role assignments.'),
    ('rbac.permission.read.platform', 'View platform permission metadata.'),
    ('rbac.role.manage.platform', 'Manage platform roles.'),
    ('rbac.role.assign.platform', 'Assign platform roles.'),
    ('rbac.role.assign.own', 'Assign supported roles inside own organization.'),
    ('rbac.super_admin.manage.platform', 'Manage super admin access.'),
    ('billing.ledger.read.own', 'View own organization ledger.'),
    ('billing.ledger.read.platform', 'View ledgers across the platform.'),
    ('billing.ledger.adjust.platform', 'Create platform ledger adjustments.'),
    ('billing.recharge_key.generate.platform', 'Generate recharge keys.'),
    ('billing.recharge_key.view_plaintext.platform', 'View recharge key plaintext.'),
    ('billing.recharge_key.redeem.own', 'Redeem recharge keys for own organization.'),
    ('billing.withdrawal.read.platform', 'View withdrawal requests and payout account details across the platform.'),
    ('billing.withdrawal.request.own', 'Request own organization withdrawals.'),
    ('billing.withdrawal.revoke.own', 'Revoke own pending withdrawals.'),
    ('billing.withdrawal.resubmit.own', 'Resubmit own revoked withdrawals with updated payout details.'),
    ('billing.withdrawal.proof.write.own', 'Upload proof metadata for own withdrawals.'),
    ('billing.withdrawal.review.platform', 'Review withdrawal requests.'),
    ('billing.withdrawal.mark_paid.platform', 'Mark withdrawals as paid.'),
    ('campaign.read.own', 'View own organization campaigns.'),
    ('campaign.write.own', 'Create or update own organization campaigns.'),
    ('campaign.status.update.own', 'Change own campaign status.'),
    ('creative.read.own', 'View own organization creatives.'),
    ('creative.write.own', 'Create or update own organization creatives.'),
    ('creative.template.read.own', 'View platform and own organization creative templates.'),
    ('creative.template.write.own', 'Create or update own organization creative templates.'),
    ('creative.template.manage.platform', 'Create or update platform creative templates.'),
    ('creative.design.read.own', 'View own organization creative designs and version history.'),
    ('creative.design.write.own', 'Create own organization creative designs and versions.'),
    ('review.queue.read.platform', 'View platform review queues.'),
    ('review.creative.decide.platform', 'Approve or reject creative reviews.'),
    ('review.site.decide.platform', 'Approve or reject site reviews.'),
    ('review.override.platform', 'Override review decisions.'),
    ('publisher.site.read.own', 'View own publisher sites.'),
    ('publisher.site.write.own', 'Create or update own publisher sites.'),
    ('publisher.site.verify.own', 'Verify own publisher sites.'),
    ('publisher.site.status.update.platform', 'Change publisher site status across the platform.'),
    ('publisher.slot.read.own', 'View own publisher slots.'),
    ('publisher.slot.write.own', 'Create or update own publisher slots.'),
    ('publisher.slot.status.update.platform', 'Change publisher slot status across the platform.'),
    ('sdk.integration.read.own', 'View own integration status.'),
    ('sdk.oauth_client.read.own', 'View own OAuth clients.'),
    ('sdk.oauth_client.write.own', 'Create or update own OAuth clients.'),
    ('sdk.oauth_client.rotate_secret.own', 'Rotate own OAuth client secrets.'),
    ('sdk.oauth_client.manage.platform', 'Manage OAuth clients across the platform.'),
    ('webhook.read.own', 'View own webhooks.'),
    ('webhook.write.own', 'Create or update own webhooks.'),
    ('webhook.secret.rotate.own', 'Rotate own webhook secrets.'),
    ('webhook.delivery.read.own', 'View own webhook deliveries.'),
    ('webhook.delivery.read.platform', 'View webhook deliveries across the platform.'),
    ('webhook.delivery.retry.platform', 'Retry webhook deliveries across the platform.'),
    ('attribution.conversion.write.own', 'Record own organization server-side conversion events.'),
    ('support.ticket.read.own', 'View own organization support tickets.'),
    ('support.ticket.read.assigned', 'View assigned support tickets.'),
    ('support.ticket.read.escalated', 'View escalated support tickets.'),
    ('support.ticket.write.own', 'Create and update own organization support tickets.'),
    ('support.ticket.note.internal.platform', 'Add internal support ticket notes.'),
    ('support.ticket.status.update.platform', 'Update support ticket status.'),
    ('report.read.own', 'View own organization reports.'),
    ('report.read.platform', 'View reports across the platform.'),
    ('report.finance.read.platform', 'View platform finance reports.'),
    ('report.review.read.platform', 'View platform review reports.'),
    ('report.export.own', 'Export own organization reports.'),
    ('report.export.platform', 'Export reports across the platform.'),
    ('report.raw_event.export.platform', 'Export raw platform events.'),
    ('config.read.platform', 'View platform configuration.'),
    ('config.write.platform', 'Update platform configuration drafts.'),
    ('config.publish.platform', 'Publish platform configuration.'),
    ('config.rollback.platform', 'Rollback platform configuration.'),
    ('feature_flag.read.platform', 'View platform feature flags.'),
    ('feature_flag.write.platform', 'Update platform feature flags.'),
    ('feature_flag.publish.platform', 'Publish platform feature flags.'),
    ('feature_flag.evaluate.platform', 'Evaluate platform feature flags for diagnostics.'),
    ('archive.job.create.platform', 'Create platform archive jobs.'),
    ('archive.manifest.read.platform', 'View platform archive manifests.'),
    ('archive.cold_query.create.platform', 'Create platform cold archive queries.'),
    ('archive.cold_query.read.platform', 'View platform cold archive query metadata.'),
    ('audit.read.own', 'View own organization audit logs.'),
    ('audit.read.platform', 'View audit logs across the platform.'),
    ('audit.read.linked', 'View audit logs linked to assigned work.'),
    ('ops.error_log.read_redacted.platform', 'View redacted platform error logs.'),
    ('ops.error_log.read_redacted.own', 'View redacted own organization error logs.'),
    ('ops.error_log.view_raw.platform', 'View raw platform error logs.'),
    ('ops.raw_export.platform', 'Export raw operational data.'),
    ('ops.dashboard.read.platform', 'View platform operations dashboard.'),
    ('ops.cron.read.platform', 'View platform cron status.'),
    ('ops.cron.rerun.platform', 'Trigger platform cron reruns.'),
    ('ops.cron.lock_clear.platform', 'Clear platform cron locks.'),
    ('ops.cache.flush.platform', 'Flush platform caches.'),
    ('ops.backup.read.platform', 'View platform backup status.'),
    ('ops.backup.restore.platform', 'Restore platform backups.'),
    ('security.event.read.platform', 'View platform security events.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT @super_admin_role_id, id
FROM permissions
ON DUPLICATE KEY UPDATE role_id = role_id;

INSERT INTO user_roles (user_id, role_id, organization_id)
VALUES (@super_admin_user_id, @super_admin_role_id, NULL);

INSERT INTO system_config_versions (
    version_id,
    config_key,
    version,
    value_json,
    created_by_user_id
)
VALUES
    (
        'cfgv_bootstrap_billing_default_revenue_share_v1',
        'billing.default_revenue_share',
        1,
        JSON_OBJECT('publisher_percent', 70),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_security_rate_limit_v1',
        'security.rate_limit',
        1,
        JSON_OBJECT('limit', 60, 'window_seconds', 60),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_attribution_default_window_seconds_v1',
        'attribution.default_window_seconds',
        1,
        JSON_OBJECT('seconds', 604800),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_serving_event_validation_v1',
        'serving.event_validation',
        1,
        JSON_OBJECT(
            'min_visible_ratio', 0.5,
            'min_visible_ms', 1000,
            'repeat_click_window_seconds', 30
        ),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_webhook_delivery_policy_v1',
        'webhook.delivery_policy',
        1,
        JSON_OBJECT('batch_size', 50, 'http_timeout_seconds', 5, 'max_retry_count', 3, 'retry_base_backoff_seconds', 300),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_security_turnstile_policy_v1',
        'security.turnstile_policy',
        1,
        JSON_OBJECT(
            'enabled', true,
            'timeout_seconds', 5,
            'protected_endpoints', JSON_ARRAY(
                'POST:/api/v1/auth/register',
                'POST:/api/v1/auth/login',
                'POST:/api/v1/auth/password-reset/request',
                'POST:/api/v1/auth/password-reset/confirm',
                'POST:/api/v1/billing/recharge-keys/redeem',
                'POST:/api/v1/oauth/consent'
            ),
            'conditional_protected_endpoints', JSON_ARRAY(
                'POST:/api/v1/ads/track',
                'GET:/api/v1/ads/click'
            )
        ),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_review_ai_policy_v1',
        'review.ai_policy',
        1,
        JSON_OBJECT(
            'enabled', true,
            'provider', 'openai_compatible',
            'base_url', 'https://api.openai.com/v1',
            'model', 'gpt-4.1-mini',
            'prompt', 'Return strict JSON with risk_score, risk_labels, reasons, and recommendation for VertoAD creative policy review.',
            'timeout_seconds', 60,
            'max_input_tokens', 12000,
            'max_output_tokens', 2000,
            'temperature', 0.2
        ),
        @super_admin_user_id
    ),
    (
        'cfgv_bootstrap_assets_upload_policy_v1',
        'assets.upload_policy',
        1,
        JSON_OBJECT(
            'upload_intent_ttl_seconds', 900,
            'blocked_extensions', JSON_ARRAY('html', 'htm', 'js', 'mjs', 'svg'),
            'blocked_content_types', JSON_ARRAY('text/html', 'application/javascript', 'text/javascript', 'image/svg+xml'),
            'types', JSON_OBJECT(
                'image', JSON_OBJECT(
                    'max_bytes', 10485760,
                    'max_width', 4096,
                    'max_height', 4096,
                    'allowed_content_types', JSON_OBJECT(
                        'png', 'image/png',
                        'jpg', 'image/jpeg',
                        'jpeg', 'image/jpeg',
                        'gif', 'image/gif',
                        'webp', 'image/webp'
                    ),
                    'magic_signatures', JSON_OBJECT(
                        'image/png', JSON_ARRAY(JSON_OBJECT('prefix_base64', 'iVBORw0KGgo=')),
                        'image/jpeg', JSON_ARRAY(JSON_OBJECT('prefix_base64', '/9j/')),
                        'image/gif', JSON_ARRAY(
                            JSON_OBJECT('prefix_ascii', 'GIF87a'),
                            JSON_OBJECT('prefix_ascii', 'GIF89a')
                        ),
                        'image/webp', JSON_ARRAY(JSON_OBJECT('prefix_ascii', 'RIFF', 'offset_ascii', JSON_OBJECT('offset', 8, 'value', 'WEBP')))
                    )
                ),
                'video', JSON_OBJECT(
                    'max_bytes', 209715200,
                    'max_width', 3840,
                    'max_height', 2160,
                    'max_duration_seconds', 120.0,
                    'allowed_content_types', JSON_OBJECT(
                        'mp4', 'video/mp4',
                        'webm', 'video/webm'
                    ),
                    'magic_signatures', JSON_OBJECT(
                        'video/mp4', JSON_ARRAY(JSON_OBJECT('offset_ascii', JSON_OBJECT('offset', 4, 'value', 'ftyp'))),
                        'video/webm', JSON_ARRAY(JSON_OBJECT('prefix_base64', 'GkXfow=='))
                    )
                ),
                'fabric_snapshot', JSON_OBJECT(
                    'max_bytes', 1048576,
                    'allowed_content_types', JSON_OBJECT('json', 'application/json'),
                    'magic_signatures', JSON_OBJECT(
                        'application/json', JSON_ARRAY(
                            JSON_OBJECT('trimmed_prefix_ascii', '{'),
                            JSON_OBJECT('trimmed_prefix_ascii', '[')
                        )
                    )
                ),
                'text', JSON_OBJECT(
                    'max_bytes', 1048576,
                    'allowed_content_types', JSON_OBJECT('txt', 'text/plain'),
                    'magic_signatures', JSON_OBJECT(
                        'text/plain', JSON_ARRAY(JSON_OBJECT('forbid_ascii_ci', '<script'))
                    )
                )
            )
        ),
        @super_admin_user_id
    );

INSERT INTO revenue_share_rules (
    scope,
    organization_id,
    site_id,
    ad_slot_id,
    share_ratio_bps,
    status,
    version,
    created_by_user_id
)
VALUES (
    'global',
    NULL,
    NULL,
    NULL,
    7000,
    'active',
    1,
    @super_admin_user_id
);

INSERT INTO audit_logs (organization_id, actor_user_id, action, subject_type, subject_id, metadata_json)
VALUES (
    @admin_organization_id,
    @super_admin_user_id,
    'bootstrap.super_admin_created',
    'user',
    @super_admin_user_id,
    JSON_OBJECT('source', 'backend/db/init-super-admin.sql')
);

COMMIT;
