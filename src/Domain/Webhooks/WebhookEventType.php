<?php

declare(strict_types=1);

namespace VertoAD\Domain\Webhooks;

final class WebhookEventType
{
    public const string REVIEW_APPROVED = 'review.approved';
    public const string REVIEW_REJECTED = 'review.rejected';
    public const string BILLING_POINTS_CHANGED = 'billing.points_changed';
    public const string CAMPAIGN_STATUS_CHANGED = 'campaign.status_changed';
    public const string CONVERSION_RECEIVED = 'conversion.received';
    public const string WITHDRAWAL_STATUS_CHANGED = 'withdrawal.status_changed';
    public const string API_CLIENT_CREATED = 'api_client.created';
    public const string API_CLIENT_SECRET_ROTATED = 'api_client.secret_rotated';
    public const string WEBHOOK_TEST = 'webhook.test';

    /** @return list<string> */
    public static function subscribable(): array
    {
        return [
            self::REVIEW_APPROVED,
            self::REVIEW_REJECTED,
            self::BILLING_POINTS_CHANGED,
            self::CAMPAIGN_STATUS_CHANGED,
            self::CONVERSION_RECEIVED,
            self::WITHDRAWAL_STATUS_CHANGED,
            self::API_CLIENT_CREATED,
            self::API_CLIENT_SECRET_ROTATED,
            self::WEBHOOK_TEST,
        ];
    }

    public static function isSubscribable(string $eventType): bool
    {
        return in_array(trim($eventType), self::subscribable(), true);
    }

    private function __construct()
    {
    }
}
