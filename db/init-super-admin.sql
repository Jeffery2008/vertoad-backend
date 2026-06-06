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
    ('system.config.read', 'Read versioned platform configuration.'),
    ('system.config.write', 'Create new platform configuration versions.'),
    ('system.config.rollback', 'Rollback platform configuration to a prior version.'),
    ('audit.logs.read', 'Read audit logs.'),
    ('audit.logs.read_raw', 'Read full raw audit or error log context.'),
    ('ledger.read', 'Read organization ledger entries and balances.'),
    ('ledger.adjust', 'Create ledger adjustments and reversals.'),
    ('recharge.keys.read', 'Read recharge key metadata.'),
    ('recharge.keys.generate', 'Generate recharge key batches.'),
    ('recharge.keys.redeem', 'Redeem recharge keys into organization points.'),
    ('recharge.keys.view_plaintext', 'View encrypted recharge key plaintext.'),
    ('publisher.sites.read', 'Read publisher site inventory.'),
    ('publisher.sites.manage', 'Create and update publisher sites.'),
    ('publisher.sites.verify', 'Run publisher site verification checks.'),
    ('publisher.slots.read', 'Read publisher ad slots.'),
    ('publisher.slots.manage', 'Create and update publisher ad slots.'),
    ('cron.status.read', 'Read protected Cron job status metadata.'),
    ('organizations.members.read', 'Read organization membership and role assignments.'),
    ('organizations.members.manage', 'Manage organization membership and role assignments.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT @super_admin_role_id, id
FROM permissions
WHERE slug IN (
    'system.config.read',
    'system.config.write',
    'system.config.rollback',
    'audit.logs.read',
    'audit.logs.read_raw',
    'ledger.read',
    'ledger.adjust',
    'recharge.keys.read',
    'recharge.keys.generate',
    'recharge.keys.redeem',
    'recharge.keys.view_plaintext',
    'publisher.sites.read',
    'publisher.sites.manage',
    'publisher.sites.verify',
    'publisher.slots.read',
    'publisher.slots.manage',
    'cron.status.read',
    'organizations.members.read',
    'organizations.members.manage'
);

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
