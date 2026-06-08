<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Publisher;

use Psr\Http\Message\ResponseInterface;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSite;
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
    public static function slot(AdSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'site_id' => $slot->siteId,
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
    public static function slots(array $slots): array
    {
        return array_map(static fn (AdSlot $slot): array => self::slot($slot), $slots);
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
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
