<?php

declare(strict_types=1);

namespace VertoAD\Service;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use InvalidArgumentException;
use RuntimeException;
use VertoAD\Domain\Publisher\PublisherSite;
use VertoAD\Domain\Publisher\PublisherSiteStatus;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttempt;
use VertoAD\Domain\Publisher\PublisherSiteVerificationAttemptStatus;
use VertoAD\Domain\Publisher\PublisherSiteVerificationChallenge;
use VertoAD\Domain\Publisher\PublisherSiteVerificationMethod;
use VertoAD\Repository\PublisherSiteVerificationAttemptRepositoryInterface;
use VertoAD\Repository\PublisherSiteRepositoryInterface;

final class PublisherSiteVerificationService
{
    private const TOKEN_PREFIX = 'va-';
    private const META_NAME = 'vertoad-site-verification';
    private const FILE_PATH = '/.well-known/vertoad-site-verification.txt';

    private readonly \Closure $httpFetcher;

    private readonly \Closure $dnsTxtResolver;

    private readonly \Closure $httpHostResolver;

    public function __construct(
        private readonly PublisherSiteRepositoryInterface $sites,
        private readonly ?PublisherSiteVerificationAttemptRepositoryInterface $attempts = null,
        ?callable $httpFetcher = null,
        ?callable $dnsTxtResolver = null,
        ?callable $httpHostResolver = null,
    ) {
        $this->httpFetcher = \Closure::fromCallable($httpFetcher ?? self::defaultHttpFetcher(...));
        $this->dnsTxtResolver = \Closure::fromCallable($dnsTxtResolver ?? self::defaultDnsTxtResolver(...));
        $this->httpHostResolver = \Closure::fromCallable($httpHostResolver ?? self::defaultHttpHostResolver(...));
    }

    public function expectedChallenge(
        int $siteId,
        PublisherSiteVerificationMethod $method,
    ): PublisherSiteVerificationChallenge {
        $site = $this->requireSite($siteId);
        $domain = $this->normalizeDomain($site->domain);
        $token = $this->expectedToken($site, $domain);

        return match ($method) {
            PublisherSiteVerificationMethod::HtmlMeta => new PublisherSiteVerificationChallenge(
                method: $method,
                token: $token,
                placement: 'meta',
                name: self::META_NAME,
                expectedValue: $token,
            ),
            PublisherSiteVerificationMethod::DnsTxt => new PublisherSiteVerificationChallenge(
                method: $method,
                token: $token,
                placement: 'dns_txt',
                name: '_vertoad.' . $domain,
                expectedValue: 'vertoad-site-verification=' . $token,
            ),
            PublisherSiteVerificationMethod::VerificationFile => new PublisherSiteVerificationChallenge(
                method: $method,
                token: $token,
                placement: 'file',
                name: self::FILE_PATH,
                expectedValue: $token,
            ),
        };
    }

    public function verify(
        int $siteId,
        PublisherSiteVerificationMethod $method,
        ?DateTimeImmutable $checkedAt = null,
    ): PublisherSite {
        $site = $this->requireSite($siteId);
        if ($site->status === PublisherSiteStatus::Verified) {
            return $site;
        }
        if ($site->status === PublisherSiteStatus::Suspended) {
            throw new RuntimeException('publisher_site_suspended');
        }

        if ($this->attempts === null) {
            throw new RuntimeException('publisher_site_verification_attempt_repository_required');
        }

        $checkedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $challenge = $this->expectedChallenge($siteId, $method);

        try {
            $observedValues = $this->probe($site, $challenge);
            $success = $this->matchesAny($challenge->expectedValue, $observedValues);
            $failureReason = $success ? null : 'expected_value_not_found';
        } catch (\Throwable $exception) {
            $observedValues = [];
            $success = false;
            $failureReason = $exception->getMessage() === 'unsafe_verification_host'
                ? 'unsafe_verification_host'
                : 'probe_unavailable';
        }

        $this->attempts->record(new PublisherSiteVerificationAttempt(
            id: null,
            siteId: $site->id,
            organizationId: $site->organizationId,
            method: $method,
            expectedValue: $challenge->expectedValue,
            observedSummary: $this->observedSummary($observedValues),
            status: $success ? PublisherSiteVerificationAttemptStatus::Success : PublisherSiteVerificationAttemptStatus::Failed,
            failureReason: $failureReason,
            createdAt: $checkedAt,
            checkedAt: $checkedAt,
        ));

        if (!$success) {
            $this->sites->markVerificationFailed($site);
            throw new RuntimeException('publisher_site_verification_failed');
        }

        return $this->sites->markVerified($site, $checkedAt);
    }

    private function requireSite(int $siteId): PublisherSite
    {
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Publisher site ID must be positive.');
        }

        $site = $this->sites->findById($siteId);
        if ($site === null) {
            throw new RuntimeException('Publisher site was not found.');
        }

        return $site;
    }

    private function expectedToken(PublisherSite $site, string $domain): string
    {
        $verificationToken = trim($site->verificationToken);
        if ($verificationToken === '') {
            throw new InvalidArgumentException('Publisher site verification token is required.');
        }

        return self::TOKEN_PREFIX . substr(hash('sha256', $site->id . '|' . $domain . '|' . $verificationToken), 0, 40);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0];
        $domain = trim($domain, ". \t\n\r\0\x0B");

        if ($domain === '') {
            throw new InvalidArgumentException('Publisher site domain is required.');
        }

        return $domain;
    }

    /**
     * @return list<string>
     */
    private function probe(PublisherSite $site, PublisherSiteVerificationChallenge $challenge): array
    {
        $domain = $this->normalizeDomain($site->domain);

        return match ($challenge->method) {
            PublisherSiteVerificationMethod::HtmlMeta => $this->probeHtmlMeta($domain),
            PublisherSiteVerificationMethod::DnsTxt => $this->probeDnsTxt($challenge->name),
            PublisherSiteVerificationMethod::VerificationFile => $this->probeVerificationFile($domain),
        };
    }

    /**
     * @return list<string>
     */
    private function probeHtmlMeta(string $domain): array
    {
        $connectIp = $this->safeHttpVerificationAddress($domain);
        $host = $this->hostForSecurityChecks($domain);

        $html = $this->fetchFirstAvailable([
            'https://' . $domain . '/',
            'http://' . $domain . '/',
        ], $connectIp, $host);

        if ($html === null) {
            return [];
        }

        return $this->extractHtmlMetaValues($html);
    }

    /**
     * @return list<string>
     */
    private function probeDnsTxt(string $host): array
    {
        $records = ($this->dnsTxtResolver)($host);
        if (!is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            if (is_array($record)) {
                $record = $record['txt'] ?? $record['value'] ?? null;
            }

            if (is_string($record) && trim($record) !== '') {
                $values[] = $this->normalizeObservedValue($record);
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function probeVerificationFile(string $domain): array
    {
        $connectIp = $this->safeHttpVerificationAddress($domain);
        $host = $this->hostForSecurityChecks($domain);

        $body = $this->fetchFirstAvailable([
            'https://' . $domain . self::FILE_PATH,
            'http://' . $domain . self::FILE_PATH,
        ], $connectIp, $host);

        return $body === null ? [] : [$this->normalizeObservedValue($body)];
    }

    /**
     * @param list<string> $urls
     */
    private function fetchFirstAvailable(array $urls, ?string $connectIp, string $host): ?string
    {
        foreach ($urls as $url) {
            $body = ($this->httpFetcher)($url, $connectIp, $host);
            if (is_string($body) && $body !== '') {
                return $body;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlMetaValues(string $html): array
    {
        $values = [];
        foreach ($this->extractHtmlMetaValuesWithDom($html) as $value) {
            $values[$value] = $value;
        }
        foreach ($this->extractHtmlMetaValuesWithRegex($html) as $value) {
            $values[$value] = $value;
        }

        return array_values($values);
    }

    /**
     * @return list<string>
     */
    private function extractHtmlMetaValuesWithDom(string $html): array
    {
        $values = [];
        if (class_exists(DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $loaded = $document->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded) {
                foreach ($document->getElementsByTagName('meta') as $meta) {
                    if (strtolower($meta->getAttribute('name')) === self::META_NAME) {
                        $content = $this->normalizeObservedValue($meta->getAttribute('content'));
                        if ($content !== '') {
                            $values[] = $content;
                        }
                    }
                }
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlMetaValuesWithRegex(string $html): array
    {
        if (!preg_match_all('/<meta\b[^>]*>/i', $html, $matches)) {
            return [];
        }

        $values = [];
        foreach ($matches[0] as $tag) {
            $name = $this->attributeValue($tag, 'name');
            if ($name === null || strtolower($name) !== self::META_NAME) {
                continue;
            }

            $content = $this->attributeValue($tag, 'content');
            if ($content !== null && trim($content) !== '') {
                $values[] = $this->normalizeObservedValue($content);
            }
        }

        return $values;
    }

    private function attributeValue(string $tag, string $attribute): ?string
    {
        if (!preg_match('/\b' . preg_quote($attribute, '/') . '\s*=\s*(["\'])(.*?)\1/i', $tag, $match)) {
            return null;
        }

        return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
    }

    private function safeHttpVerificationAddress(string $domain): string
    {
        $host = $this->hostForSecurityChecks($domain);
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('unsafe_verification_host');
        }

        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = ($this->httpHostResolver)($host);
            if (!is_array($addresses) || $addresses === []) {
                throw new RuntimeException('unsafe_verification_host');
            }

            foreach ($addresses as $address) {
                if (!is_string($address) || !$this->isPublicIp($address)) {
                    throw new RuntimeException('unsafe_verification_host');
                }
            }

            return (string) reset($addresses);
        }

        if (!$this->isPublicIp($host)) {
            throw new RuntimeException('unsafe_verification_host');
        }

        return $host;
    }

    private function isPublicIp(string $host): bool
    {
        $publicIp = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        return $publicIp !== false;
    }

    private function hostForSecurityChecks(string $domain): string
    {
        $host = strtolower(trim($domain));
        if (str_starts_with($host, '[')) {
            $closingBracket = strpos($host, ']');
            if ($closingBracket !== false) {
                return substr($host, 1, $closingBracket - 1);
            }
        }

        $withoutPort = preg_replace('/:\d+$/', '', $host);
        if (is_string($withoutPort)) {
            $host = $withoutPort;
        }

        return trim($host, ". \t\n\r\0\x0B");
    }

    /**
     * @param list<string> $observedValues
     */
    private function matchesAny(string $expectedValue, array $observedValues): bool
    {
        foreach ($observedValues as $observedValue) {
            if (hash_equals($expectedValue, $this->normalizeObservedValue($observedValue))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeObservedValue(string $observedValue): string
    {
        return trim($observedValue, "\"' \t\n\r\0\x0B");
    }

    /**
     * @param list<string> $observedValues
     */
    private function observedSummary(array $observedValues): ?string
    {
        if ($observedValues === []) {
            return null;
        }

        $normalized = array_map(fn (string $value): string => $this->normalizeObservedValue($value), $observedValues);
        $joined = implode("\n", $normalized);

        return sprintf('count:%d length:%d sha256:%s', count($normalized), strlen($joined), hash('sha256', $joined));
    }

    private static function defaultHttpFetcher(string $url, ?string $connectIp = null, ?string $host = null): ?string
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            return null;
        }

        $requestUrl = self::connectUrl($url, $connectIp);
        $host ??= (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $context = stream_context_create([
            'http' => [
                'follow_location' => 0,
                'header' => $host === '' ? '' : 'Host: ' . $host,
                'ignore_errors' => true,
                'max_redirects' => 0,
                'timeout' => 5,
                'user_agent' => 'VertoAD site verification',
            ],
            'ssl' => [
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $body = @file_get_contents($requestUrl, false, $context);

        return is_string($body) ? $body : null;
    }

    private static function connectUrl(string $url, ?string $connectIp): string
    {
        if ($connectIp === null || $connectIp === '') {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $host = filter_var($connectIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $connectIp . ']' : $connectIp;
        $authority = $host . (isset($parts['port']) ? ':' . (string) $parts['port'] : '');
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';

        return (string) $parts['scheme'] . '://' . $authority . $path . $query;
    }

    /**
     * @return list<string>
     */
    private static function defaultDnsTxtResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        return self::txtValuesFromDnsRecords(is_array($records) ? $records : []);
    }

    /**
     * @return list<string>
     */
    private static function defaultHttpHostResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        return self::ipAddressesFromDnsRecords(is_array($records) ? $records : []);
    }

    /**
     * @param list<mixed> $records
     * @return list<string>
     */
    private static function txtValuesFromDnsRecords(array $records): array
    {
        $values = [];
        foreach ($records as $record) {
            if (is_array($record) && isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }

    /**
     * @param list<mixed> $records
     * @return list<string>
     */
    private static function ipAddressesFromDnsRecords(array $records): array
    {
        $values = [];
        foreach ($records as $record) {
            if (is_array($record) && isset($record['ip']) && is_string($record['ip'])) {
                $values[] = $record['ip'];
            }
            if (is_array($record) && isset($record['ipv6']) && is_string($record['ipv6'])) {
                $values[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($values));
    }
}
