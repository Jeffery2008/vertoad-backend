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
    ('billing.withdrawal.request.own', 'Request own organization withdrawals.'),
    ('billing.withdrawal.revoke.own', 'Revoke own pending withdrawals.'),
    ('billing.withdrawal.proof.write.own', 'Upload proof metadata for own withdrawals.'),
    ('billing.withdrawal.review.platform', 'Review withdrawal requests.'),
    ('billing.withdrawal.mark_paid.platform', 'Mark withdrawals as paid.'),
    ('campaign.read.own', 'View own organization campaigns.'),
    ('campaign.write.own', 'Create or update own organization campaigns.'),
    ('campaign.status.update.own', 'Change own campaign status.'),
    ('creative.read.own', 'View own organization creatives.'),
    ('creative.write.own', 'Create or update own organization creatives.'),
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
