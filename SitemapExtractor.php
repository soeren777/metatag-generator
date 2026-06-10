<?php

class SitemapExtractor
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Returns array of URLs for a given domain.
     * Tries sitemap.xml first, falls back to internal link extraction.
     */
    public function getUrls(string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $domain  = parse_url($baseUrl, PHP_URL_HOST);
        $scheme  = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $base    = $scheme . '://' . $domain;

        // 1. Try sitemap
        $urls = $this->trySitemap($base);

        // 2. Fallback: extract internal links from homepage
        if (empty($urls)) {
            $urls = $this->extractLinks($baseUrl, $base);
        }

        // Deduplicate, filter to same domain, limit
        $urls = array_values(array_unique($urls));
        $urls = array_filter($urls, fn($u) => parse_url($u, PHP_URL_HOST) === $domain);
        $urls = array_values($urls);

        $max = $this->config['multicrawl_max_urls'] ?? 500;
        return array_slice($urls, 0, $max);
    }

    private function trySitemap(string $base): array
    {
        // Common sitemap locations
        $candidates = [
            $base . '/sitemap.xml',
            $base . '/sitemap_index.xml',
            $base . '/sitemap/',
        ];

        foreach ($candidates as $url) {
            $xml = $this->fetch($url);
            if (!$xml) continue;

            $urls = $this->parseSitemap($xml, $base);
            if (!empty($urls)) return $urls;
        }

        // Try robots.txt for Sitemap: directive
        $robots = $this->fetch($base . '/robots.txt');
        if ($robots) {
            preg_match_all('/^Sitemap:\s*(.+)$/mi', $robots, $m);
            foreach ($m[1] as $sitemapUrl) {
                $xml = $this->fetch(trim($sitemapUrl));
                if (!$xml) continue;
                $urls = $this->parseSitemap($xml, $base);
                if (!empty($urls)) return $urls;
            }
        }

        return [];
    }

    private function parseSitemap(string $xml, string $base): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$doc->loadXML($xml)) return [];
        libxml_clear_errors();

        $urls = [];
        $max  = $this->config['multicrawl_max_urls'] ?? 500;

        // Sitemap index – recurse into child sitemaps
        $sitemaps = $doc->getElementsByTagName('sitemap');
        if ($sitemaps->length > 0) {
            foreach ($sitemaps as $s) {
                $locs = $s->getElementsByTagName('loc');
                if (!$locs->length) continue;
                $loc = trim($locs->item(0)->textContent);
                if (!$loc) continue;
                $child = $this->fetch($loc);
                if ($child) {
                    $childUrls = $this->parseSitemap($child, $base);
                    $urls = array_merge($urls, $childUrls);
                }
                if (count($urls) >= $max) break;
            }
            return $urls;
        }

        // Regular sitemap
        foreach ($doc->getElementsByTagName('url') as $u) {
            $locs = $u->getElementsByTagName('loc');
            if ($locs->length) {
                $loc = trim($locs->item(0)->textContent);
                if ($loc) $urls[] = $loc;
            }
        }

        return $urls;
    }

    private function extractLinks(string $url, string $base): array
    {
        $html = $this->fetch($url);
        if (!$html) return [];

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $links = $xpath->query('//a[@href]/@href');
        $urls  = [];

        foreach ($links as $link) {
            $href = trim($link->nodeValue);
            if (!$href || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) continue;

            // Resolve relative URLs
            if (str_starts_with($href, '/')) {
                $href = $base . $href;
            } elseif (!str_starts_with($href, 'http')) {
                $href = rtrim($url, '/') . '/' . $href;
            }

            // Strip fragments and query strings for cleaner URLs
            $parts = parse_url($href);
            if (!isset($parts['host'])) continue;
            $clean = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . ($parts['path'] ?? '/');
            $urls[] = rtrim($clean, '/') ?: $clean;
        }

        // Always include base URL
        array_unshift($urls, $url);

        return array_values(array_unique($urls));
    }

    private function fetch(string $url): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->config['crawler_timeout'] ?? 10,
            CURLOPT_USERAGENT      => $this->config['crawler_user_agent'] ?? 'MetaTagOptimizer/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($result && $code >= 200 && $code < 400) ? $result : false;
    }
}
