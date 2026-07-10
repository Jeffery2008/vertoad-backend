<?php

declare(strict_types=1);

namespace VertoAD\Service\Serving;

final readonly class UserAgentDeviceClassifier
{
    public function classify(?string $userAgent): ?string
    {
        $userAgent = strtolower(trim($userAgent ?? ''));
        if ($userAgent === '') {
            return null;
        }

        if (
            str_contains($userAgent, 'ipad')
            || str_contains($userAgent, 'tablet')
            || str_contains($userAgent, 'kindle')
            || str_contains($userAgent, 'silk/')
            || str_contains($userAgent, 'playbook')
            || (
                str_contains($userAgent, 'android')
                && !str_contains($userAgent, 'mobile')
                && !str_contains($userAgent, 'windows phone')
            )
        ) {
            return 'tablet';
        }

        if (
            str_contains($userAgent, 'mobile')
            || str_contains($userAgent, 'iphone')
            || str_contains($userAgent, 'ipod')
            || str_contains($userAgent, 'windows phone')
        ) {
            return 'mobile';
        }

        if (
            str_contains($userAgent, 'windows nt')
            || str_contains($userAgent, 'macintosh')
            || str_contains($userAgent, 'x11')
            || str_contains($userAgent, 'cros')
            || str_contains($userAgent, 'linux x86_64')
        ) {
            return 'desktop';
        }

        return null;
    }
}
