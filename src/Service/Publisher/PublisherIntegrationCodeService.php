<?php

declare(strict_types=1);

namespace VertoAD\Service\Publisher;

use VertoAD\Domain\Publisher\AdSlot;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Repository\AdSlotRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final readonly class PublisherIntegrationCodeService
{
    private const SERVE_PATH = '/api/v1/ads/serve';
    private const SDK_SCRIPT_PATH = '/vertoad-sdk.js';
    private const PACKAGE_NAME = '@vertoad/sdk';
    private const VIEWER_ID_PLACEHOLDER = '{viewer_id}';

    public function __construct(
        private PublisherSiteRepositoryInterface $sites,
        private AdSlotRepositoryInterface $slots,
        private string $sdkPublicBaseUrl,
        private string $adsPublicBaseUrl,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function forSlot(int $organizationId, int $siteId, int $slotId): ?array
    {
        if ($organizationId <= 0 || $siteId <= 0 || $slotId <= 0) {
            return null;
        }

        $site = $this->sites->findById($siteId);
        if ($site === null || $site->organizationId !== $organizationId) {
            return null;
        }

        $slot = $this->slots->findForSiteInOrganization($siteId, $slotId, $organizationId);
        if ($slot === null) {
            return null;
        }

        $sdkBaseUrl = $this->baseUrl($this->sdkPublicBaseUrl);
        $adsBaseUrl = $this->baseUrl($this->adsPublicBaseUrl);
        $slotSize = [
            'width' => $slot->size->width,
            'height' => $slot->size->height,
        ];

        return [
            'site_id' => $site->id,
            'slot_id' => $slot->id,
            'organization_id' => $site->organizationId,
            'slot_key' => $slot->slotKey,
            'site_domain' => $site->domain,
            'viewer_id_strategy' => 'first_party_stable_id',
            'iframe_url_template' => $this->iframeUrlTemplate($adsBaseUrl, $site, $slot),
            'hosted_script_snippet' => $this->hostedScriptSnippet($sdkBaseUrl, $adsBaseUrl, $site, $slot),
            'npm_install_command' => 'npm install ' . self::PACKAGE_NAME,
            'npm_usage_snippet' => $this->npmUsageSnippet($adsBaseUrl, $site, $slot, $slotSize),
            'slot_size' => $slotSize,
            'responsive' => $slot->responsive,
            'responsive_rules' => $slot->responsiveRules,
            'sdk_public_base_url' => $sdkBaseUrl,
            'ads_public_base_url' => $adsBaseUrl,
        ];
    }

    private function iframeUrlTemplate(string $adsBaseUrl, PublisherSite $site, AdSlot $slot): string
    {
        $query = http_build_query([
            'site_id' => $site->id,
            'slot_id' => $slot->id,
            'viewer_id' => self::VIEWER_ID_PLACEHOLDER,
            'width' => $slot->size->width,
            'height' => $slot->size->height,
        ], '', '&', PHP_QUERY_RFC3986);

        $query = str_replace('viewer_id=%7Bviewer_id%7D', 'viewer_id=' . self::VIEWER_ID_PLACEHOLDER, $query);

        return $adsBaseUrl . self::SERVE_PATH . '?' . $query;
    }

    private function hostedScriptSnippet(
        string $sdkBaseUrl,
        string $adsBaseUrl,
        PublisherSite $site,
        AdSlot $slot,
    ): string
    {
        $containerId = 'vertoad-slot-' . $slot->id;
        $scriptUrl = $this->htmlAttribute($sdkBaseUrl . self::SDK_SCRIPT_PATH);
        $config = [
            'siteId' => $site->id,
            'slotId' => $slot->id,
            'container' => '#' . $containerId,
            'adsBaseUrl' => $adsBaseUrl,
        ];

        if ($slot->responsive) {
            $config['responsive'] = true;
            $config['height'] = $slot->size->height;
        } else {
            $config['size'] = [
                'width' => $slot->size->width,
                'height' => $slot->size->height,
            ];
        }

        $config['lazyLoad'] = true;
        $configJson = $this->snippetJson($config);

        return '<script async src="' . $scriptUrl . '"></script>' . "\n"
            . '<div id="' . $containerId . '"></div>' . "\n"
            . "<script>\n"
            . "  window.VertoAD = window.VertoAD || [];\n"
            . '  window.VertoAD.push(' . $configJson . ");\n"
            . '</script>';
    }

    /**
     * @param array{width:int,height:int} $slotSize
     */
    private function npmUsageSnippet(
        string $adsBaseUrl,
        PublisherSite $site,
        AdSlot $slot,
        array $slotSize,
    ): string {
        $config = [
            'adsBaseUrl' => $adsBaseUrl,
            'siteId' => $site->id,
            'slotId' => $slot->id,
            'container' => '#vertoad-slot-' . $slot->id,
        ];

        if ($slot->responsive) {
            $config['width'] = '100%';
            $config['height'] = $slotSize['height'];
        } else {
            $config['width'] = $slotSize['width'];
            $config['height'] = $slotSize['height'];
        }

        $config['lazy'] = true;
        $configJson = $this->snippetJson($config);

        return "import { createVertoAdSlot } from '" . self::PACKAGE_NAME . "';\n\n"
            . 'createVertoAdSlot(' . $configJson . ');';
    }

    private function baseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Publisher integration public base URL must not be empty.');
        }

        return rtrim($url, '/');
    }

    private function htmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $value */
    private function snippetJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_PRETTY_PRINT
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_UNESCAPED_SLASHES,
        );
    }
}
