<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttempt;
use VertoAD\Domain\Publisher\PublisherSiteVerificationChallenge;

final class PublisherJson
{
    /** @return array<string, mixed> */
    public static function site(PublisherSite $site): array
    {
        return [
            'id' => $site->id,
            'organization_id' => $site->organizationId,
            'name' => $site->name,
            'domain' => $site->domain,
            'status' => $site->status->value,
            'verification_token' => $site->verificationToken,
            'verified_at' => $site->verifiedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<PublisherSite> $sites
     * @return list<array<string, mixed>>
     */
    public static function sites(array $sites): array
    {
        return array_map(static fn (PublisherSite $site): array => self::site($site), $sites);
    }

    /** @return array<string, mixed> */
    public static function challenge(PublisherSiteVerificationChallenge $challenge): array
    {
        return [
            'method' => $challenge->method->value,
            'token' => $challenge->token,
            'placement' => $challenge->placement,
            'name' => $challenge->name,
            'expected_value' => $challenge->expectedValue,
        ];
    }

    /** @return array<string, mixed> */
    public static function attempt(PublisherSiteVerificationAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'site_id' => $attempt->siteId,
            'organization_id' => $attempt->organizationId,
            'method' => $attempt->method->value,
            'observed_summary' => $attempt->observedSummary,
            'status' => $attempt->status->value,
            'failure_reason' => $attempt->failureReason,
            'created_at' => $attempt->createdAt->format(DATE_ATOM),
            'checked_at' => $attempt->checkedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<PublisherSiteVerificationAttempt> $attempts
     * @return list<array<string, mixed>>
     */
    public static function attempts(array $attempts): array
    {
        return array_map(static fn (PublisherSiteVerificationAttempt $attempt): array => self::attempt($attempt), $attempts);
    }

    /** @return array<string, mixed> */
    public static function slot(AdSlot $slot, int $organizationId): array
    {
        return [
            'id' => $slot->id,
            'site_id' => $slot->siteId,
            'organization_id' => $organizationId,
            'name' => $slot->name,
            'slot_key' => $slot->slotKey,
            'width' => $slot->size->width,
            'height' => $slot->size->height,
            'size_preset' => $slot->presetKey,
            'responsive' => $slot->responsive,
            'responsive_rules' => $slot->responsiveRules,
            'status' => $slot->status,
        ];
    }

    /**
     * @param list<AdSlot> $slots
     * @return list<array<string, mixed>>
     */
    public static function slots(array $slots, int $organizationId): array
    {
        return array_map(static fn (AdSlot $slot): array => self::slot($slot, $organizationId), $slots);
    }

    /**
     * @param array<string, AdSlotSize> $presets
     * @return array<string, array{width:int,height:int}>
     */
    public static function presets(array $presets): array
    {
        $payload = [];
        foreach ($presets as $key => $size) {
            $payload[$key] = ['width' => $size->width, 'height' => $size->height];
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public static function write(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        ));

        return $response;
    }
}
