<?php

declare(strict_types=1);

namespace VertoAD\Install;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\PasswordHasher;
use VertoAD\Service\PermissionInventory;

final readonly class BootstrapSeeder
{
    /**
     * @param array{installation_id: string, app_key: string, oauth_encryption_key: string, oauth_client_id: string, oauth_client_secret: string, cron_api_token: string, webhook_signing_secret: string} $secrets
     * @return array{installation_id: string, admin_user_id: int, organization_id: int, oauth_client_id: string}
     */
    public function seed(
        Connection $connection,
        InstallInput $input,
        #[\SensitiveParameter] array $secrets,
        ?string $clientIp,
        ?string $userAgent,
        string $requestId,
    ): array {
        return $connection->transactional(function () use ($connection, $input, $secrets, $clientIp, $userAgent, $requestId): array {
            $this->assertFreshDatabase($connection);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $timestamp = $now->format('Y-m-d H:i:s');

            $connection->insert('users', [
                'email' => $input->adminEmail,
                'password_hash' => (new PasswordHasher())->hash($input->adminPassword),
                'display_name' => $input->adminDisplayName,
                'status' => 'active',
                'email_verified_at' => $timestamp,
            ]);
            $userId = $this->lastInsertId($connection, 'administrator');

            $connection->insert('organizations', [
                'name' => $input->organizationName,
                'slug' => $input->organizationSlug,
                'billing_status' => 'active',
            ]);
            $organizationId = $this->lastInsertId($connection, 'organization');

            $connection->insert('organization_members', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'status' => 'active',
                'title' => 'Super Admin',
            ]);
            $connection->insert('roles', [
                'organization_id' => null,
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'description' => 'Unrestricted platform administration role.',
                'is_system' => 1,
            ]);
            $roleId = $this->lastInsertId($connection, 'super admin role');

            $permissionCodes = [];
            foreach ((new PermissionInventory())->all() as $permission) {
                $connection->insert('permissions', [
                    'slug' => $permission['code'],
                    'description' => $permission['description'],
                ]);
                $permissionId = $this->lastInsertId($connection, 'permission');
                $permissionCodes[] = $permission['code'];
                $connection->insert('role_permissions', [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
            $connection->insert('user_roles', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'organization_id' => null,
            ]);

            $connection->insert('oauth_clients', [
                'organization_id' => $organizationId,
                'owner_user_id' => $userId,
                'client_identifier' => $secrets['oauth_client_id'],
                'name' => 'VertoAD Initial Client',
                'secret_hash' => (new OAuthClientSecretHasher())->hash($secrets['oauth_client_secret']),
                'redirect_uris_json' => $this->json([$input->oauthRedirectUri]),
                'grant_types_json' => $this->json(['authorization_code', 'client_credentials', 'refresh_token']),
                'scopes_json' => $this->json($permissionCodes),
                'is_confidential' => 1,
            ]);
            $oauthClientDatabaseId = $this->lastInsertId($connection, 'OAuth client');

            foreach ($permissionCodes as $permissionCode) {
                $connection->insert('oauth_scopes', [
                    'scope_identifier' => $permissionCode,
                    'description' => 'Bootstrap permission scope ' . $permissionCode . '.',
                    'is_default' => 0,
                ]);
                $scopeId = $this->lastInsertId($connection, 'OAuth scope');
                $connection->insert('oauth_client_scopes', [
                    'client_id' => $oauthClientDatabaseId,
                    'scope_id' => $scopeId,
                ]);
            }

            foreach (BootstrapConfigCatalog::all() as $configKey => $value) {
                $connection->insert('system_config_versions', [
                    'version_id' => 'cfgv_install_' . sha1($configKey),
                    'config_key' => $configKey,
                    'version' => 1,
                    'value_json' => $this->json($value),
                    'created_by_user_id' => $userId,
                ]);
            }

            $connection->insert('revenue_share_rules', [
                'scope' => 'global',
                'organization_id' => null,
                'site_id' => null,
                'ad_slot_id' => null,
                'share_ratio_bps' => 7000,
                'status' => 'active',
                'version' => 1,
                'created_by_user_id' => $userId,
            ]);

            $packedIp = $clientIp === null ? null : @inet_pton($clientIp);
            $packedIp = $packedIp === false ? null : $packedIp;
            $connection->insert('app_installations', [
                'id' => 1,
                'installation_id' => $secrets['installation_id'],
                'admin_user_id' => $userId,
                'organization_id' => $organizationId,
                'initial_oauth_client_id' => $oauthClientDatabaseId,
                'installed_at' => $timestamp,
                'installed_by_ip' => $packedIp,
                'request_id' => $requestId,
                'metadata_json' => $this->json([
                    'installer' => 'web',
                    'permission_count' => count($permissionCodes),
                    'config_keys' => array_keys(BootstrapConfigCatalog::all()),
                ]),
            ]);
            $connection->insert('audit_logs', [
                'organization_id' => $organizationId,
                'actor_user_id' => $userId,
                'action' => 'installation.completed',
                'subject_type' => 'installation',
                'subject_id' => 1,
                'ip_address' => $packedIp,
                'user_agent' => $userAgent,
                'request_id' => $requestId,
                'metadata_json' => $this->json([
                    'installation_id' => $secrets['installation_id'],
                    'source' => 'backend web installer',
                ]),
            ]);

            return [
                'installation_id' => $secrets['installation_id'],
                'admin_user_id' => $userId,
                'organization_id' => $organizationId,
                'oauth_client_id' => $secrets['oauth_client_id'],
            ];
        });
    }

    private function assertFreshDatabase(Connection $connection): void
    {
        foreach (['users', 'organizations', 'roles', 'permissions', 'oauth_clients', 'system_config_versions', 'revenue_share_rules', 'app_installations'] as $table) {
            $count = $connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($table)
                ->fetchOne();
            if ((int) $count !== 0) {
                throw new \RuntimeException('Installation requires an empty migrated database.');
            }
        }
    }

    private function lastInsertId(Connection $connection, string $entity): int
    {
        $id = (int) $connection->lastInsertId();
        if ($id <= 0) {
            throw new \RuntimeException('Unable to persist bootstrap ' . $entity . '.');
        }

        return $id;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
