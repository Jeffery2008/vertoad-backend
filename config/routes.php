<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use VertoAD\Http\Action\Cron\CronRunAction;
use VertoAD\Http\Action\Cron\CronStatusAction;
use VertoAD\Http\Action\HealthAction;
use VertoAD\Http\Action\Operations\CreateConfigVersionAction;
use VertoAD\Http\Action\Operations\GetRawOperationErrorContextAction;
use VertoAD\Http\Action\Operations\ListConfigVersionsAction;
use VertoAD\Http\Action\Operations\ListOperationErrorsAction;
use VertoAD\Http\Action\Operations\ListWebhookDeliveriesAction;
use VertoAD\Http\Action\Operations\OperationsSummaryAction;
use VertoAD\Http\Action\Operations\RetryWebhookDeliveryAction;
use VertoAD\Http\Action\Operations\RollbackConfigVersionAction;
use VertoAD\Http\Action\Organizations\ListOrganizationMembersAction;
use VertoAD\Http\Action\OAuth\CreateOAuthClientAction;
use VertoAD\Http\Action\OAuth\AuthorizeAction;
use VertoAD\Http\Action\OAuth\ConsentAction;
use VertoAD\Http\Action\OAuth\ListOAuthClientsAction;
use VertoAD\Http\Action\OAuth\RevokeAction;
use VertoAD\Http\Action\OAuth\RotateOAuthClientSecretAction;
use VertoAD\Http\Action\OAuth\TokenAction;
use VertoAD\Http\Action\Auth\LoginAction;
use VertoAD\Http\Action\Auth\LogoutAction;
use VertoAD\Http\Action\Auth\MeAction;
use VertoAD\Http\Action\Auth\PasswordResetConfirmAction;
use VertoAD\Http\Action\Auth\PasswordResetRequestAction;
use VertoAD\Http\Action\Auth\RegisterAction;
use VertoAD\Http\Action\Archive\ArchiveManifestAction;
use VertoAD\Http\Action\Archive\CreateArchiveJobAction;
use VertoAD\Http\Action\Archive\CreateColdQueryAction;
use VertoAD\Http\Action\Archive\GetColdQueryAction;
use VertoAD\Http\Action\Assets\ConfirmAssetUploadAction;
use VertoAD\Http\Action\Assets\CreateAssetUploadIntentAction;
use VertoAD\Http\Action\Attribution\ConversionPixelAction;
use VertoAD\Http\Action\Attribution\ServerConversionAction;
use VertoAD\Http\Action\Billing\BillingBalanceAction;
use VertoAD\Http\Action\Billing\BillingLedgerListAction;
use VertoAD\Http\Action\Billing\GenerateRechargeKeyBatchAction;
use VertoAD\Http\Action\Billing\RechargeKeyRedeemAction;
use VertoAD\Http\Action\Billing\RevealRechargeKeyPlaintextAction;
use VertoAD\Http\Action\Billing\WithdrawalAction;
use VertoAD\Http\Action\Campaigns\CreateCampaignAction;
use VertoAD\Http\Action\Campaigns\GetCampaignAction;
use VertoAD\Http\Action\Campaigns\ListCampaignsAction;
use VertoAD\Http\Action\Campaigns\UpdateCampaignAction;
use VertoAD\Http\Action\FeatureFlags\CreateFeatureFlagAction;
use VertoAD\Http\Action\FeatureFlags\EvaluateFeatureFlagAction;
use VertoAD\Http\Action\FeatureFlags\ListFeatureFlagsAction;
use VertoAD\Http\Action\Permissions\PermissionInventoryAction;
use VertoAD\Http\Action\Publisher\CreatePublisherAdSlotAction;
use VertoAD\Http\Action\Publisher\CreatePublisherSiteAction;
use VertoAD\Http\Action\Publisher\GetPublisherSiteVerificationChallengeAction;
use VertoAD\Http\Action\Publisher\ListPublisherSiteVerificationAttemptsAction;
use VertoAD\Http\Action\Publisher\ListPublisherAdSlotPresetsAction;
use VertoAD\Http\Action\Publisher\ListPublisherAdSlotsAction;
use VertoAD\Http\Action\Publisher\ListPublisherSitesAction;
use VertoAD\Http\Action\Publisher\VerifyPublisherSiteAction;
use VertoAD\Http\Action\Review\ApproveReviewAction;
use VertoAD\Http\Action\Review\GetReviewStatusAction;
use VertoAD\Http\Action\Review\RejectReviewAction;
use VertoAD\Http\Action\Review\StartAiReviewAction;
use VertoAD\Http\Action\Reporting\ReportDashboardAction;
use VertoAD\Http\Action\Serving\ClickAction;
use VertoAD\Http\Action\Serving\ServeAction;
use VertoAD\Http\Action\Serving\ServeFrameAction;
use VertoAD\Http\Action\Serving\TrackAction;
use VertoAD\Http\Action\Support\AddSupportTicketNoteAction;
use VertoAD\Http\Action\Support\CreateSupportTicketAction;
use VertoAD\Http\Action\Support\ListSupportTicketsAction;
use VertoAD\Http\Action\Support\UpdateSupportTicketStatusAction;
use VertoAD\Http\Action\Webhooks\CreateWebhookEndpointAction;
use VertoAD\Http\Action\Webhooks\ListWebhookDeliveriesAction as ListOwnWebhookDeliveriesAction;
use VertoAD\Http\Action\Webhooks\ListWebhookEndpointsAction;
use VertoAD\Http\Action\Webhooks\RotateWebhookEndpointSecretAction;
use VertoAD\Http\Action\Webhooks\TestWebhookEndpointAction;
use VertoAD\Http\Action\Webhooks\UpdateWebhookEndpointAction;
use VertoAD\Http\Auth\PermissionRequirement;
use VertoAD\Http\Middleware\AuthenticateRequestMiddleware;
use VertoAD\Http\Middleware\CronAuthMiddleware;
use VertoAD\Http\Middleware\RateLimitMiddleware;
use VertoAD\Http\Middleware\RequirePermissionMiddleware;
use VertoAD\Http\Middleware\TurnstileMiddleware;
use VertoAD\Service\TenantAccessService;

return static function (App $app): void {
    $permission = static function (string $permission) use ($app): RequirePermissionMiddleware {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Application container is required for permission middleware.');
        }

        return new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forOrganization($permission),
        );
    };
    $platformPermission = static function (string $permission) use ($app): RequirePermissionMiddleware {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Application container is required for permission middleware.');
        }

        return new RequirePermissionMiddleware(
            $app->getResponseFactory(),
            $container->get(TenantAccessService::class),
            PermissionRequirement::forPlatform($permission),
        );
    };

    $app->get('/api/v1/health', HealthAction::class);
    $app->get('/api/v1/permissions', PermissionInventoryAction::class);
    $app->post('/api/v1/auth/register', RegisterAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class);
    $app->post('/api/v1/auth/login', LoginAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class);
    $app->post('/api/v1/auth/logout', LogoutAction::class)->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/auth/password-reset/request', PasswordResetRequestAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class);
    $app->post('/api/v1/auth/password-reset/confirm', PasswordResetConfirmAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class);
    $app->get('/api/v1/auth/me', MeAction::class)->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/organizations/{organization_id}/members', ListOrganizationMembersAction::class)
        ->add($permission('organizations.members.read'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/billing/balance', BillingBalanceAction::class)->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/billing/ledger', BillingLedgerListAction::class)->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/recharge-keys/generate', GenerateRechargeKeyBatchAction::class)
        ->add($platformPermission('billing.recharge_key.generate.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/recharge-keys/{key_id}/reveal', RevealRechargeKeyPlaintextAction::class)
        ->add($platformPermission('billing.recharge_key.view_plaintext.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/recharge-keys/redeem', RechargeKeyRedeemAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class)
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/withdrawals', [WithdrawalAction::class, 'request'])
        ->add($permission('billing.withdrawal.request.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/paid', [WithdrawalAction::class, 'markPaid'])
        ->add($platformPermission('billing.withdrawal.mark_paid.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/reject', [WithdrawalAction::class, 'reject'])
        ->add($platformPermission('billing.withdrawal.review.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/revoke', [WithdrawalAction::class, 'revoke'])
        ->add($permission('billing.withdrawal.revoke.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/proofs', [WithdrawalAction::class, 'createProofIntent'])
        ->add($permission('billing.withdrawal.proof.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/billing/withdrawals/{withdrawal_id}/proofs/confirm', [WithdrawalAction::class, 'confirmProof'])
        ->add($permission('billing.withdrawal.proof.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/assets/upload-intents', CreateAssetUploadIntentAction::class)->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/assets/confirm', ConfirmAssetUploadAction::class)->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/campaigns', ListCampaignsAction::class)
        ->add($permission('campaign.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/campaigns', CreateCampaignAction::class)
        ->add($permission('campaign.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/campaigns/{campaign_id}', GetCampaignAction::class)
        ->add($permission('campaign.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->patch('/api/v1/campaigns/{campaign_id}', UpdateCampaignAction::class)
        ->add($permission('campaign.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/reviews/assets/{asset_id}/ai-review', StartAiReviewAction::class)
        ->add($permission('creative.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/reviews/{review_id}', GetReviewStatusAction::class)
        ->add($permission('creative.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/reviews/{review_id}/approve', ApproveReviewAction::class)
        ->add($platformPermission('review.creative.decide.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/reviews/{review_id}/reject', RejectReviewAction::class)
        ->add($platformPermission('review.creative.decide.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/publisher/sites', ListPublisherSitesAction::class)
        ->add($permission('publisher.site.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/publisher/sites', CreatePublisherSiteAction::class)
        ->add($permission('publisher.site.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/publisher/sites/{site_id}/verification-challenge', GetPublisherSiteVerificationChallengeAction::class)
        ->add($permission('publisher.site.verify.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/publisher/sites/{site_id}/verify', VerifyPublisherSiteAction::class)
        ->add($permission('publisher.site.verify.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/publisher/sites/{site_id}/verification-attempts', ListPublisherSiteVerificationAttemptsAction::class)
        ->add($permission('publisher.site.verify.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/publisher/ad-slot-presets', ListPublisherAdSlotPresetsAction::class)
        ->add($permission('publisher.slot.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/publisher/sites/{site_id}/slots', ListPublisherAdSlotsAction::class)
        ->add($permission('publisher.slot.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/publisher/sites/{site_id}/slots', CreatePublisherAdSlotAction::class)
        ->add($permission('publisher.slot.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/reports/dashboard', ReportDashboardAction::class)
        ->add($permission('report.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/oauth/clients', ListOAuthClientsAction::class)
        ->add($permission('sdk.oauth_client.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/oauth/clients', CreateOAuthClientAction::class)
        ->add($permission('sdk.oauth_client.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/oauth/clients/{client_id}/rotate-secret', RotateOAuthClientSecretAction::class)
        ->add($permission('sdk.oauth_client.rotate_secret.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/oauth/authorize', AuthorizeAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class)
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/oauth/consent', ConsentAction::class)
        ->add(TurnstileMiddleware::class)
        ->add(RateLimitMiddleware::class)
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/oauth/token', TokenAction::class)
        ->add(RateLimitMiddleware::class);
    $app->post('/api/v1/oauth/revoke', RevokeAction::class)
        ->add(RateLimitMiddleware::class);
    $app->get('/api/v1/webhooks/endpoints', ListWebhookEndpointsAction::class)
        ->add($permission('webhook.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/webhooks/endpoints', CreateWebhookEndpointAction::class)
        ->add($permission('webhook.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->patch('/api/v1/webhooks/endpoints/{endpoint_id}', UpdateWebhookEndpointAction::class)
        ->add($permission('webhook.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/webhooks/endpoints/{endpoint_id}/rotate-secret', RotateWebhookEndpointSecretAction::class)
        ->add($permission('webhook.secret.rotate.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/webhooks/endpoints/{endpoint_id}/test', TestWebhookEndpointAction::class)
        ->add($permission('webhook.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/webhooks/deliveries', ListOwnWebhookDeliveriesAction::class)
        ->add($permission('webhook.delivery.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/attribution/conversions', ServerConversionAction::class);
    $app->get('/api/v1/attribution/pixel', ConversionPixelAction::class);
    $app->post('/api/v1/archive/jobs', CreateArchiveJobAction::class)
        ->add($platformPermission('archive.job.create.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/archive/manifests/{manifest_id}', ArchiveManifestAction::class)
        ->add($platformPermission('archive.manifest.read.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/archive/cold-queries', CreateColdQueryAction::class)
        ->add($platformPermission('archive.cold_query.create.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/archive/cold-queries/{job_id}', GetColdQueryAction::class)
        ->add($platformPermission('archive.cold_query.read.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/operations/summary', OperationsSummaryAction::class)
        ->add($platformPermission('ops.dashboard.read.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/operations/errors', ListOperationErrorsAction::class)
        ->add($platformPermission('ops.error_log.read_redacted.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/operations/errors/{error_id}/raw-context', GetRawOperationErrorContextAction::class)
        ->add($platformPermission('ops.error_log.view_raw.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/operations/config/versions', ListConfigVersionsAction::class)
        ->add($platformPermission('config.read.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/operations/config/versions', CreateConfigVersionAction::class)
        ->add($platformPermission('config.write.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/operations/config/versions/{version_id}/rollback', RollbackConfigVersionAction::class)
        ->add($platformPermission('config.rollback.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/operations/webhooks/deliveries', ListWebhookDeliveriesAction::class)
        ->add($platformPermission('webhook.delivery.read.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/operations/webhooks/deliveries/{delivery_id}/retry', RetryWebhookDeliveryAction::class)
        ->add($platformPermission('webhook.delivery.retry.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/support/tickets', CreateSupportTicketAction::class)
        ->add($permission('support.ticket.write.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/support/tickets', ListSupportTicketsAction::class)
        ->add($permission('support.ticket.read.own'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/support/tickets/{ticket_id}/notes', AddSupportTicketNoteAction::class)
        ->add($platformPermission('support.ticket.note.internal.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/support/tickets/{ticket_id}/status', UpdateSupportTicketStatusAction::class)
        ->add($platformPermission('support.ticket.status.update.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/feature-flags', CreateFeatureFlagAction::class)
        ->add($platformPermission('feature_flag.write.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/feature-flags', ListFeatureFlagsAction::class)
        ->add($platformPermission('feature_flag.read.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->post('/api/v1/feature-flags/{flag_key}/evaluate', EvaluateFeatureFlagAction::class)
        ->add($platformPermission('feature_flag.evaluate.platform'))
        ->add(AuthenticateRequestMiddleware::class);
    $app->get('/api/v1/ads/serve', ServeFrameAction::class);
    $app->post('/api/v1/ads/serve', ServeAction::class);
    $app->post('/api/v1/ads/track', TrackAction::class);
    $app->get('/api/v1/ads/click', ClickAction::class);

    $app->group('/api/v1/cron', function (RouteCollectorProxy $group): void {
        $group->get('/status', CronStatusAction::class);
        $group->get('/jobs/{job_name}/run', CronRunAction::class);
    })->add(CronAuthMiddleware::class);
};
