<?php

declare(strict_types=1);

namespace VertoAD\Domain\Publisher;

enum PublisherSiteVerificationMethod: string
{
    case HtmlMeta = 'html_meta';
    case DnsTxt = 'dns_txt';
    case VerificationFile = 'verification_file';
}
