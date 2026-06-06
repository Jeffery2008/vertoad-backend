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
    ('system.manage', 'Manage global platform settings.'),
    ('organizations.manage', 'Manage organizations and memberships.'),
    ('billing.manage', 'Manage recharge keys and ledger operations.'),
    ('ads.manage', 'Manage sites, ad slots, campaigns, and creatives.'),
    ('audit.read', 'Read audit and error logs.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT @super_admin_role_id, id
FROM permissions
WHERE slug IN (
    'system.manage',
    'organizations.manage',
    'billing.manage',
    'ads.manage',
    'audit.read'
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
