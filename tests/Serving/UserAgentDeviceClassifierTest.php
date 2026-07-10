<?php

declare(strict_types=1);

namespace VertoAD\Tests\Serving;

use PHPUnit\Framework\TestCase;
use VertoAD\Service\Serving\UserAgentDeviceClassifier;

final class UserAgentDeviceClassifierTest extends TestCase
{
    public function testClassifiesSupportedDeviceFamiliesAndReturnsUnknownDefensively(): void
    {
        $classifier = new UserAgentDeviceClassifier();
        $cases = [
            [null, null],
            ['   ', null],
            ['curl/8.0', null],
            ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)', 'tablet'],
            ['Example Tablet Browser', 'tablet'],
            ['Mozilla/5.0 (Linux; U; en-US) AppleWebKit Kindle/3.0', 'tablet'],
            ['Mozilla/5.0 (Linux; U; Android 9) Silk/120.0', 'tablet'],
            ['Mozilla/5.0 (PlayBook; U; RIM Tablet OS 1.0)', 'tablet'],
            ['Mozilla/5.0 (Linux; Android 13; Pixel C)', 'tablet'],
            ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile', 'mobile'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 'mobile'],
            ['Mozilla/5.0 (iPod touch; CPU iPhone OS 16_0 like Mac OS X)', 'mobile'],
            ['Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1)', 'mobile'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'desktop'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)', 'desktop'],
            ['Mozilla/5.0 (X11; Ubuntu; Linux i686)', 'desktop'],
            ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0)', 'desktop'],
            ['Mozilla/5.0 (Linux x86_64) Firefox/127.0', 'desktop'],
        ];

        foreach ($cases as [$userAgent, $expected]) {
            self::assertSame($expected, $classifier->classify($userAgent), (string) $userAgent);
        }
    }
}
