<?php

declare(strict_types=1);

namespace VertoAD\Tests;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\PermissionInventory;

final class SensitiveRoutePermissionContractTest extends TestCase
{
    public function testSensitiveRoutesDeclareFineGrainedPermissionMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/config/routes.php');

        foreach ($this->routePermissions() as [$method, $route, $permission, $factory]) {
            $pattern = preg_quote("\$app->" . strtolower($method) . "('" . $route . "'", '/')
                . '(?s:.{0,260})'
                . preg_quote('->add($' . $factory . "('" . $permission . "'))", '/');

            self::assertTrue(
                preg_match('/' . $pattern . '/', $routes) === 1,
                $method . ' ' . $route . ' must enforce ' . $permission . ' through ' . $factory . '.',
            );
        }
    }

    public function testPermissionInventoryContainsEverySensitiveRoutePermission(): void
    {
        $codes = array_column((new PermissionInventory())->all(), 'code');

        foreach ($this->routePermissions() as [, , $permission]) {
            self::assertContains($permission, $codes, $permission . ' must be in the RBAC inventory.');
        }
    }

    /**
     * @return list<array{0:string, 1:string, 2:string, 3:string}>
     */
    private function routePermissions(): array
    {
        return [
            ['POST', '/api/v1/billing/recharge-keys/generate', 'billing.recharge_key.generate.platform', 'platformPermission'],
            ['POST', '/api/v1/billing/recharge-keys/{key_id}/reveal', 'billing.recharge_key.view_plaintext.platform', 'platformPermission'],
            ['POST', '/api/v1/billing/ledger/adjustments', 'billing.ledger.adjust.platform', 'platformPermission'],
            ['POST', '/api/v1/billing/ledger/{entry_id}/reversals', 'billing.ledger.adjust.platform', 'platformPermission'],
            ['POST', '/api/v1/billing/withdrawals', 'billing.withdrawal.request.own', 'permission'],
            ['POST', '/api/v1/billing/withdrawals/{withdrawal_id}/paid', 'billing.withdrawal.mark_paid.platform', 'platformPermission'],
            ['POST', '/api/v1/billing/withdrawals/{withdrawal_id}/reject', 'billing.withdrawal.review.platform', 'platformPermission'],
            ['POST', '/api/v1/billing/withdrawals/{withdrawal_id}/revoke', 'billing.withdrawal.revoke.own', 'permission'],
            ['POST', '/api/v1/billing/withdrawals/{withdrawal_id}/resubmit', 'billing.withdrawal.resubmit.own', 'permission'],
            ['POST', '/api/v1/billing/withdrawals/{withdrawal_id}/proofs', 'billing.withdrawal.proof.write.own', 'permission'],
            ['POST', '/api/v1/billing/withdrawals/{withdrawal_id}/proofs/confirm', 'billing.withdrawal.proof.write.own', 'permission'],
            ['GET', '/api/v1/organizations/{organization_id}/members', 'organizations.members.read', 'permission'],
            ['GET', '/api/v1/campaigns', 'campaign.read.own', 'permission'],
            ['POST', '/api/v1/campaigns', 'campaign.write.own', 'permission'],
            ['GET', '/api/v1/campaigns/{campaign_id}', 'campaign.read.own', 'permission'],
            ['PATCH', '/api/v1/campaigns/{campaign_id}', 'campaign.write.own', 'permission'],
            ['POST', '/api/v1/reviews/assets/{asset_id}/ai-review', 'creative.write.own', 'permission'],
            ['GET', '/api/v1/reviews', 'review.queue.read.platform', 'platformPermission'],
            ['GET', '/api/v1/reviews/{review_id}', 'creative.read.own', 'permission'],
            ['POST', '/api/v1/reviews/{review_id}/approve', 'review.creative.decide.platform', 'platformPermission'],
            ['POST', '/api/v1/reviews/{review_id}/reject', 'review.creative.decide.platform', 'platformPermission'],
            ['GET', '/api/v1/reports/dashboard', 'report.read.own', 'permission'],
            ['GET', '/api/v1/oauth/clients', 'sdk.oauth_client.read.own', 'permission'],
            ['POST', '/api/v1/oauth/clients', 'sdk.oauth_client.write.own', 'permission'],
            ['POST', '/api/v1/oauth/clients/{client_id}/rotate-secret', 'sdk.oauth_client.rotate_secret.own', 'permission'],
            ['GET', '/api/v1/webhooks/endpoints', 'webhook.read.own', 'permission'],
            ['POST', '/api/v1/webhooks/endpoints', 'webhook.write.own', 'permission'],
            ['PATCH', '/api/v1/webhooks/endpoints/{endpoint_id}', 'webhook.write.own', 'permission'],
            ['POST', '/api/v1/webhooks/endpoints/{endpoint_id}/rotate-secret', 'webhook.secret.rotate.own', 'permission'],
            ['POST', '/api/v1/webhooks/endpoints/{endpoint_id}/test', 'webhook.write.own', 'permission'],
            ['GET', '/api/v1/webhooks/deliveries', 'webhook.delivery.read.own', 'permission'],
            ['POST', '/api/v1/attribution/conversions', 'attribution.conversion.write.own', 'permission'],
            ['POST', '/api/v1/archive/jobs', 'archive.job.create.platform', 'platformPermission'],
            ['GET', '/api/v1/archive/manifests/{manifest_id}', 'archive.manifest.read.platform', 'platformPermission'],
            ['POST', '/api/v1/archive/cold-queries', 'archive.cold_query.create.platform', 'platformPermission'],
            ['GET', '/api/v1/archive/cold-queries/{job_id}', 'archive.cold_query.read.platform', 'platformPermission'],
            ['GET', '/api/v1/operations/summary', 'ops.dashboard.read.platform', 'platformPermission'],
            ['GET', '/api/v1/operations/errors', 'ops.error_log.read_redacted.platform', 'platformPermission'],
            ['GET', '/api/v1/operations/errors/{error_id}/raw-context', 'ops.error_log.view_raw.platform', 'platformPermission'],
            ['GET', '/api/v1/operations/config/versions', 'config.read.platform', 'platformPermission'],
            ['POST', '/api/v1/operations/config/versions', 'config.write.platform', 'platformPermission'],
            ['POST', '/api/v1/operations/config/versions/{version_id}/rollback', 'config.rollback.platform', 'platformPermission'],
            ['GET', '/api/v1/operations/webhooks/deliveries', 'webhook.delivery.read.platform', 'platformPermission'],
            ['POST', '/api/v1/operations/webhooks/deliveries/{delivery_id}/retry', 'webhook.delivery.retry.platform', 'platformPermission'],
            ['POST', '/api/v1/support/tickets', 'support.ticket.write.own', 'permission'],
            ['GET', '/api/v1/support/tickets', 'support.ticket.read.own', 'permission'],
            ['POST', '/api/v1/support/tickets/{ticket_id}/notes', 'support.ticket.note.internal.platform', 'platformPermission'],
            ['POST', '/api/v1/support/tickets/{ticket_id}/status', 'support.ticket.status.update.platform', 'platformPermission'],
            ['POST', '/api/v1/feature-flags', 'feature_flag.write.platform', 'platformPermission'],
            ['GET', '/api/v1/feature-flags', 'feature_flag.read.platform', 'platformPermission'],
            ['POST', '/api/v1/feature-flags/{flag_key}/evaluate', 'feature_flag.evaluate.platform', 'platformPermission'],
        ];
    }
}
