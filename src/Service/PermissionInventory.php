<?php

declare(strict_types=1);

namespace VertoAD\Service;

final class PermissionInventory
{
    /**
     * @return list<array{code: string, domain: string, scope: string, description: string, sensitive: bool, audit_required: bool}>
     */
    public function all(): array
    {
        return array_map(
            fn (array $permission): array => $this->withDerivedFields($permission[0], $permission[1], $permission[2]),
            [
                ['org.read.own', 'View own organization details.', false],
                ['org.read.platform', 'View organization details across the platform.', false],
                ['org.write.own', 'Update own organization details.', false],
                ['org.write.platform', 'Update organization details across the platform.', false],
                ['org.status.update.platform', 'Suspend or restore organizations across the platform.', true],
                ['organizations.members.read', 'Read own organization members and role assignments.', false],
                ['organizations.members.manage', 'Manage own organization members and role assignments.', true],
                ['rbac.permission.read.platform', 'View platform permission metadata.', false],
                ['rbac.role.manage.platform', 'Manage platform roles.', true],
                ['rbac.role.assign.platform', 'Assign platform roles.', true],
                ['rbac.role.assign.own', 'Assign supported roles inside own organization.', true],
                ['rbac.super_admin.manage.platform', 'Manage super admin access.', true],
                ['billing.ledger.read.own', 'View own organization ledger.', false],
                ['billing.ledger.read.platform', 'View ledgers across the platform.', false],
                ['billing.ledger.adjust.platform', 'Create platform ledger adjustments.', true],
                ['billing.recharge_key.generate.platform', 'Generate recharge keys.', true],
                ['billing.recharge_key.view_plaintext.platform', 'View recharge key plaintext.', true],
                ['billing.recharge_key.redeem.own', 'Redeem recharge keys for own organization.', false],
                ['billing.withdrawal.request.own', 'Request own organization withdrawals.', true],
                ['billing.withdrawal.revoke.own', 'Revoke own pending withdrawals.', true],
                ['billing.withdrawal.proof.write.own', 'Upload proof metadata for own withdrawals.', true],
                ['billing.withdrawal.review.platform', 'Review withdrawal requests.', true],
                ['billing.withdrawal.mark_paid.platform', 'Mark withdrawals as paid.', true],
                ['campaign.read.own', 'View own organization campaigns.', false],
                ['campaign.write.own', 'Create or update own organization campaigns.', false],
                ['campaign.status.update.own', 'Change own campaign status.', true],
                ['creative.read.own', 'View own organization creatives.', false],
                ['creative.write.own', 'Create or update own organization creatives.', false],
                ['review.queue.read.platform', 'View platform review queues.', false],
                ['review.creative.decide.platform', 'Approve or reject creative reviews.', true],
                ['review.site.decide.platform', 'Approve or reject site reviews.', true],
                ['review.override.platform', 'Override review decisions.', true],
                ['publisher.site.read.own', 'View own publisher sites.', false],
                ['publisher.site.write.own', 'Create or update own publisher sites.', false],
                ['publisher.site.verify.own', 'Verify own publisher sites.', true],
                ['publisher.site.status.update.platform', 'Change publisher site status across the platform.', true],
                ['publisher.slot.read.own', 'View own publisher slots.', false],
                ['publisher.slot.write.own', 'Create or update own publisher slots.', false],
                ['publisher.slot.status.update.platform', 'Change publisher slot status across the platform.', true],
                ['sdk.integration.read.own', 'View own integration status.', false],
                ['sdk.oauth_client.read.own', 'View own OAuth clients.', false],
                ['sdk.oauth_client.write.own', 'Create or update own OAuth clients.', false],
                ['sdk.oauth_client.rotate_secret.own', 'Rotate own OAuth client secrets.', true],
                ['sdk.oauth_client.manage.platform', 'Manage OAuth clients across the platform.', true],
                ['webhook.read.own', 'View own webhooks.', false],
                ['webhook.write.own', 'Create or update own webhooks.', false],
                ['webhook.secret.rotate.own', 'Rotate own webhook secrets.', true],
                ['webhook.delivery.read.own', 'View own webhook deliveries.', false],
                ['webhook.delivery.read.platform', 'View webhook deliveries across the platform.', false],
                ['webhook.delivery.retry.platform', 'Retry webhook deliveries across the platform.', true],
                ['support.ticket.read.own', 'View own organization support tickets.', false],
                ['support.ticket.read.assigned', 'View assigned support tickets.', false],
                ['support.ticket.read.escalated', 'View escalated support tickets.', false],
                ['support.ticket.write.own', 'Create and update own organization support tickets.', false],
                ['support.ticket.note.internal.platform', 'Add internal support ticket notes.', true],
                ['support.ticket.status.update.platform', 'Update support ticket status.', true],
                ['report.read.own', 'View own organization reports.', false],
                ['report.read.platform', 'View reports across the platform.', false],
                ['report.finance.read.platform', 'View platform finance reports.', false],
                ['report.review.read.platform', 'View platform review reports.', false],
                ['report.export.own', 'Export own organization reports.', true],
                ['report.export.platform', 'Export reports across the platform.', true],
                ['report.raw_event.export.platform', 'Export raw platform events.', true],
                ['config.read.platform', 'View platform configuration.', false],
                ['config.write.platform', 'Update platform configuration drafts.', true],
                ['config.publish.platform', 'Publish platform configuration.', true],
                ['config.rollback.platform', 'Rollback platform configuration.', true],
                ['feature_flag.read.platform', 'View platform feature flags.', false],
                ['feature_flag.write.platform', 'Update platform feature flags.', true],
                ['feature_flag.publish.platform', 'Publish platform feature flags.', true],
                ['feature_flag.evaluate.platform', 'Evaluate platform feature flags for diagnostics.', false],
                ['archive.job.create.platform', 'Create platform archive jobs.', true],
                ['archive.manifest.read.platform', 'View platform archive manifests.', false],
                ['archive.cold_query.create.platform', 'Create platform cold archive queries.', true],
                ['archive.cold_query.read.platform', 'View platform cold archive query metadata.', false],
                ['audit.read.own', 'View own organization audit logs.', false],
                ['audit.read.platform', 'View audit logs across the platform.', false],
                ['audit.read.linked', 'View audit logs linked to assigned work.', false],
                ['ops.error_log.read_redacted.platform', 'View redacted platform error logs.', false],
                ['ops.error_log.read_redacted.own', 'View redacted own organization error logs.', false],
                ['ops.error_log.view_raw.platform', 'View raw platform error logs.', true],
                ['ops.raw_export.platform', 'Export raw operational data.', true],
                ['ops.dashboard.read.platform', 'View platform operations dashboard.', false],
                ['ops.cron.read.platform', 'View platform cron status.', false],
                ['ops.cron.rerun.platform', 'Trigger platform cron reruns.', true],
                ['ops.cron.lock_clear.platform', 'Clear platform cron locks.', true],
                ['ops.cache.flush.platform', 'Flush platform caches.', true],
                ['ops.backup.read.platform', 'View platform backup status.', false],
                ['ops.backup.restore.platform', 'Restore platform backups.', true],
                ['security.event.read.platform', 'View platform security events.', false],
            ],
        );
    }

    /**
     * @return array{code: string, domain: string, scope: string, description: string, sensitive: bool, audit_required: bool}
     */
    private function withDerivedFields(string $code, string $description, bool $sensitive): array
    {
        $parts = explode('.', $code);

        return [
            'code' => $code,
            'domain' => $parts[0],
            'scope' => $parts[array_key_last($parts)],
            'description' => $description,
            'sensitive' => $sensitive,
            'audit_required' => $sensitive,
        ];
    }
}
