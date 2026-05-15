<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class LinkPreviewService
{
    /**
     * @return array{success: bool, error?: string, url?: string, title?: string|null, description?: string|null, image?: string|null, site_name?: string|null}
     */
    public function previewForRequest(Request $request): array
    {
        $url = trim((string) $request->query('url', ''));
        if ($url === '' || mb_strlen($url) > 2048) {
            return ['success' => false, 'error' => 'Invalid url'];
        }

        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return ['success' => false, 'error' => 'Invalid url'];
        }

        $cacheKey = 'link_preview:'.sha1($normalized);

        /** @var array<string, mixed> $cached */
        $cached = Cache::get($cacheKey, []);
        if (($cached['success'] ?? null) === true) {
            return $cached;
        }

        $result = $this->fetchPreview($normalized, $request);
        Cache::put($cacheKey, $result, now()->addHours(24));

        return $result;
    }

    /**
     * Persistable fragment (no "success" key) for team_messages.link_preview.
     *
     * @return array{url: string, title: ?string, description: ?string, image: ?string, site_name: ?string}|null
     */
    public function storedPreviewForBody(string $body, ?Request $request = null): ?array
    {
        $url = $this->firstHttpUrlFromText($body);
        if ($url === null) {
            return null;
        }

        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return null;
        }

        $cacheKey = 'link_preview:'.sha1($normalized);

        /** @var array<string, mixed> $cached */
        $cached = Cache::get($cacheKey, []);
        if (($cached['success'] ?? null) !== true) {
            $cached = $this->fetchPreview($normalized, $request);
            Cache::put($cacheKey, $cached, now()->addHours(24));
        }

        if (($cached['success'] ?? null) !== true) {
            return null;
        }

        return [
            'url' => (string) ($cached['url'] ?? $normalized),
            'title' => isset($cached['title']) ? (is_string($cached['title']) ? $cached['title'] : null) : null,
            'description' => isset($cached['description']) ? (is_string($cached['description']) ? $cached['description'] : null) : null,
            'image' => isset($cached['image']) ? (is_string($cached['image']) ? $cached['image'] : null) : null,
            'site_name' => isset($cached['site_name']) ? (is_string($cached['site_name']) ? $cached['site_name'] : null) : null,
        ];
    }

    public function firstHttpUrlFromText(string $text): ?string
    {
        if (preg_match('~\bhttps?://[^\s<>"{}|\\^`\[\]]+~iu', $text, $m)) {
            $u = rtrim($m[0], '.,;:!?)');

            return $u !== '' ? $u : null;
        }

        return null;
    }

    public function normalizeUrl(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (str_starts_with(strtolower($v), 'www.')) {
            $v = 'https://'.$v;
        }

        $parts = parse_url($v);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (filter_var($host, FILTER_VALIDATE_IP, $flags) === false) {
                return null;
            }
        } elseif (! $this->hostResolvesOnlyToPublicAddresses($host)) {
            return null;
        }

        return $v;
    }

    /**
     * @return array{success: bool, url: string, title: string|null, description: string|null, image: string|null, site_name: string|null}
     */
    private function fetchPreview(string $url, ?Request $request): array
    {
        try {
            $acceptLang = '';
            if ($request !== null) {
                $acceptLang = trim((string) $request->header('Accept-Language', ''));
            }

            $resp = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'ChatswitchLinkPreview/1.0',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => $acceptLang !== '' ? $acceptLang : 'ru-RU,ru;q=0.9,en-US;q=0.7,en;q=0.6',
                ])
                ->get($url);

            $contentType = strtolower((string) $resp->header('Content-Type', ''));
            if (! $resp->successful() || ! str_contains($contentType, 'text/html')) {
                return [
                    'success' => true,
                    'url' => $url,
                    'title' => null,
                    'description' => null,
                    'image' => null,
                    'site_name' => parse_url($url, PHP_URL_HOST) ?: null,
                ];
            }

            $html = (string) $resp->body();
            if (strlen($html) > 700_000) {
                $html = substr($html, 0, 700_000);
            }

            $meta = $this->parseOpenGraph($html);
            $title = $meta['title'] ?? null;
            $desc = $meta['description'] ?? null;
            $image = $meta['image'] ?? null;
            $site = $meta['site_name'] ?? null;

            if ($site === null) {
                $site = parse_url($url, PHP_URL_HOST) ?: null;
            }

            if (is_string($image) && $image !== '') {
                $image = $this->resolveUrl($url, $image);
            }

            return [
                'success' => true,
                'url' => $url,
                'title' => $this->trimOrNull($title, 180),
                'description' => $this->trimOrNull($desc, 280),
                'image' => $this->trimOrNull($image, 2048),
                'site_name' => $this->trimOrNull($site, 120),
            ];
        } catch (\Throwable) {
            return [
                'success' => true,
                'url' => $url,
                'title' => null,
                'description' => null,
                'image' => null,
                'site_name' => parse_url($url, PHP_URL_HOST) ?: null,
            ];
        }
    }

    private function hostResolvesOnlyToPublicAddresses(string $host): bool
    {
        $ips = [];
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }

        if ($ips === []) {
            return false;
        }

        $flags = \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE;
        foreach ($ips as $ip) {
            if (filter_var($ip, \FILTER_VALIDATE_IP, $flags) === false) {
                return false;
            }
        }

        return true;
    }

    /** @return array{title?: string, description?: string, image?: string, site_name?: string} */
    private function parseOpenGraph(string $html): array
    {
        $out = [];
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);

        $getMeta = static function (string $q) use ($xpath): ?string {
            $nodes = $xpath->query($q);
            if (! $nodes || $nodes->length === 0) {
                return null;
            }
            $node = $nodes->item(0);
            if (! $node instanceof \DOMElement) {
                return null;
            }
            $v = trim((string) $node->getAttribute('content'));

            return $v !== '' ? $v : null;
        };

        $out['title'] = $getMeta("//meta[@property='og:title']") ?? $getMeta("//meta[@name='twitter:title']");
        $out['description'] = $getMeta("//meta[@property='og:description']") ?? $getMeta("//meta[@name='description']") ?? $getMeta("//meta[@name='twitter:description']");
        $out['image'] = $getMeta("//meta[@property='og:image']") ?? $getMeta("//meta[@name='twitter:image']");
        $out['site_name'] = $getMeta("//meta[@property='og:site_name']");

        if (! isset($out['title']) || $out['title'] === null) {
            $titleNodes = $xpath->query('//title');
            if ($titleNodes && $titleNodes->length > 0) {
                $t = trim((string) $titleNodes->item(0)?->textContent);
                if ($t !== '') {
                    $out['title'] = $t;
                }
            }
        }

        return array_filter($out, static fn ($v) => is_string($v) && trim($v) !== '');
    }

    private function resolveUrl(string $baseUrl, string $maybeRelative): ?string
    {
        $v = trim($maybeRelative);
        if ($v === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $v)) {
            return $v;
        }
        if (str_starts_with($v, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$v;
        }

        $base = parse_url($baseUrl);
        if (! is_array($base)) {
            return null;
        }
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? null;
        if (! $host) {
            return null;
        }

        $prefix = $scheme.'://'.$host;
        $port = $base['port'] ?? null;
        if (is_int($port)) {
            $prefix .= ':'.$port;
        }

        if (str_starts_with($v, '/')) {
            return $prefix.$v;
        }

        $path = (string) ($base['path'] ?? '/');
        $dir = str_contains($path, '/') ? rtrim(substr($path, 0, strrpos($path, '/') ?: 0), '/') : '';
        $dir = $dir !== '' ? $dir : '';

        return $prefix.($dir !== '' ? $dir.'/' : '/').$v;
    }

    private function trimOrNull(?string $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $v) ?: '');
        if ($t === '') {
            return null;
        }
        if (mb_strlen($t) > $max) {
            $t = mb_substr($t, 0, $max);
        }

        return $t;
    }
}
