<?php

declare(strict_types=1);

namespace VertoAD\Service;

use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\AdSlotSize;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final class AdSlotSetupService
{
    private const MIN_DIMENSION = 1;
    private const MAX_DIMENSION = 4096;

    /** @var array<string, array{width:int,height:int}> */
    private const PRESET_SIZES = [
        'leaderboard' => ['width' => 728, 'height' => 90],
        'medium_rectangle' => ['width' => 300, 'height' => 250],
        'mobile_banner' => ['width' => 320, 'height' => 50],
        'wide_skyscraper' => ['width' => 160, 'height' => 600],
        'half_page' => ['width' => 300, 'height' => 600],
        'billboard' => ['width' => 970, 'height' => 250],
    ];

    public function __construct(
        private readonly PublisherSiteRepositoryInterface $sites,
        private readonly AdSlotRepositoryInterface $slots,
    ) {
    }

    /**
     * @param list<array{min_width:int,width:int,height:int}>|null $responsiveRules
     */
    public function createPresetSlot(
        int $siteId,
        string $name,
        string $slotKey,
        string $presetKey,
        bool $responsive,
        ?array $responsiveRules,
    ): AdSlot {
        $presetKey = trim($presetKey);
        if (!array_key_exists($presetKey, self::PRESET_SIZES)) {
            throw new InvalidArgumentException('Unknown ad slot preset size.');
        }

        $preset = self::PRESET_SIZES[$presetKey];

        return $this->createSlot(
            siteId: $siteId,
            name: $name,
            slotKey: $slotKey,
            size: new AdSlotSize($preset['width'], $preset['height']),
            responsive: $responsive,
            responsiveRules: $responsiveRules,
            presetKey: $presetKey,
        );
    }

    /**
     * @param list<array{min_width:int,width:int,height:int}>|null $responsiveRules
     */
    public function createCustomSlot(
        int $siteId,
        string $name,
        string $slotKey,
        AdSlotSize $size,
        bool $responsive,
        ?array $responsiveRules,
    ): AdSlot {
        return $this->createSlot(
            siteId: $siteId,
            name: $name,
            slotKey: $slotKey,
            size: $size,
            responsive: $responsive,
            responsiveRules: $responsiveRules,
            presetKey: null,
        );
    }

    /**
     * @return array<string, AdSlotSize>
     */
    public function presetSizes(): array
    {
        $sizes = [];
        foreach (self::PRESET_SIZES as $key => $size) {
            $sizes[$key] = new AdSlotSize($size['width'], $size['height']);
        }

        return $sizes;
    }

    /**
     * @param list<array{min_width:int,width:int,height:int}>|null $responsiveRules
     */
    private function createSlot(
        int $siteId,
        string $name,
        string $slotKey,
        AdSlotSize $size,
        bool $responsive,
        ?array $responsiveRules,
        ?string $presetKey,
    ): AdSlot {
        $this->requireVerifiedSite($siteId);
        $this->validateSize($size);

        $slot = new AdSlot(
            id: null,
            siteId: $siteId,
            name: $this->normalizeName($name),
            slotKey: $this->normalizeSlotKey($slotKey),
            size: $size,
            responsive: $responsive,
            responsiveRules: $this->normalizeResponsiveRules($responsive, $responsiveRules),
            presetKey: $presetKey,
        );

        return $this->slots->store($slot);
    }

    private function requireVerifiedSite(int $siteId): void
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Publisher site ID must be positive.');
        }

        $site = $this->sites->findById($siteId);
        if ($site === null) {
            throw new RuntimeException('Publisher site was not found.');
        }

        if ($site->status !== PublisherSiteStatus::Verified || $site->verifiedAt === null) {
            throw new RuntimeException('Publisher site must be verified before ad slots can be created.');
        }
    }

    private function validateSize(AdSlotSize $size): void
    {
        if ($size->width < self::MIN_DIMENSION || $size->width > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('Ad slot width must be between 1 and 4096 pixels.');
        }

        if ($size->height < self::MIN_DIMENSION || $size->height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('Ad slot height must be between 1 and 4096 pixels.');
        }
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Ad slot name is required.');
        }

        if (strlen($name) > 160) {
            throw new InvalidArgumentException('Ad slot name must be 160 characters or fewer.');
        }

        return $name;
    }

    private function normalizeSlotKey(string $slotKey): string
    {
        $slotKey = trim($slotKey);
        if ($slotKey === '') {
            throw new InvalidArgumentException('Ad slot key is required.');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,119}$/', $slotKey)) {
            throw new InvalidArgumentException('Ad slot key must use lowercase letters, numbers, underscores, or hyphens.');
        }

        return $slotKey;
    }

    /**
     * @param list<array{min_width:int,width:int,height:int}>|null $rules
     * @return list<array{min_width:int,width:int,height:int}>
     */
    private function normalizeResponsiveRules(bool $responsive, ?array $rules): array
    {
        if (!$responsive) {
            return [];
        }

        if ($rules === null || $rules === []) {
            throw new InvalidArgumentException('Responsive ad slots require at least one responsive rule.');
        }

        $normalized = [];
        $previousMinWidth = -1;
        foreach ($rules as $rule) {
            foreach (['min_width', 'width', 'height'] as $key) {
                if (!array_key_exists($key, $rule) || !is_int($rule[$key])) {
                    throw new InvalidArgumentException('Responsive ad slot rules require integer min_width, width, and height.');
                }
            }

            if ($rule['min_width'] < 0 || $rule['min_width'] > self::MAX_DIMENSION) {
                throw new InvalidArgumentException('Responsive ad slot rule min_width must be between 0 and 4096 pixels.');
            }

            if ($rule['min_width'] <= $previousMinWidth) {
                throw new InvalidArgumentException('Responsive ad slot rules must be ordered by increasing min_width.');
            }

            $this->validateSize(new AdSlotSize($rule['width'], $rule['height']));
            $normalized[] = [
                'min_width' => $rule['min_width'],
                'width' => $rule['width'],
                'height' => $rule['height'],
            ];
            $previousMinWidth = $rule['min_width'];
        }

        return $normalized;
    }
}
