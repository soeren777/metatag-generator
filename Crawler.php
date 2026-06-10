<?php

class Crawler
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function fetch(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Ungültige URL');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            throw new InvalidArgumentException('Nur HTTP/HTTPS erlaubt');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->config['crawler_timeout'],
            CURLOPT_USERAGENT      => $this->config['crawler_user_agent'],
            CURLOPT_HTTPHEADER     => ['Accept-Language: de,en;q=0.9'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("Crawl-Fehler: $err");
        if ($code >= 400) throw new RuntimeException("HTTP $code beim Abrufen der URL");
        if (!$html) throw new RuntimeException('Leere Antwort vom Server');

        return $this->parse($html, $url);
    }

    private function parse(string $html, string $url): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        // Bestehende Metatags
        $existing = [
            'title'       => $this->getTitle($xpath),
            'description' => $this->getMeta($xpath, 'description'),
            'og_title'    => $this->getOgTag($xpath, 'og:title'),
            'og_desc'     => $this->getOgTag($xpath, 'og:description'),
            'og_image'    => $this->getOgTag($xpath, 'og:image'),
            'og_type'     => $this->getOgTag($xpath, 'og:type'),
            'tw_card'     => $this->getOgTag($xpath, 'twitter:card'),
            'tw_title'    => $this->getOgTag($xpath, 'twitter:title'),
            'tw_desc'     => $this->getOgTag($xpath, 'twitter:description'),
            'canonical'   => $this->getCanonical($xpath),
            'robots'      => $this->getMeta($xpath, 'robots'),
            'keywords'    => $this->getMeta($xpath, 'keywords'),
            'author'      => $this->getMeta($xpath, 'author'),
        ];

        // Seitenstruktur
        $h1    = $this->getFirstText($xpath, '//h1');
        $h2s   = $this->getAllText($xpath, '//h2', 5);
        $paras = $this->getAllText($xpath, '//p[string-length(.) > 80]', 6);

        // JSON-LD erkennen
        $jsonld = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $data = json_decode($node->textContent, true);
            if ($data) $jsonld[] = $data;
        }

        // Seitentyp raten
        $pageType = $this->guessPageType($jsonld, $url, $h1);

        // Rohtext für AI (gekürzt)
        $bodyText = $this->extractBodyText($xpath);

        return [
            'url'       => $url,
            'existing'  => $existing,
            'h1'        => $h1,
            'h2s'       => $h2s,
            'paragraphs'=> $paras,
            'jsonld'    => $jsonld,
            'page_type' => $pageType,
            'body_text' => mb_substr($bodyText, 0, $this->config['max_content_length']),
            'domain'    => parse_url($url, PHP_URL_HOST),
        ];
    }

    private function getTitle(DOMXPath $xp): string
    {
        $nodes = $xp->query('//title');
        return $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    private function getMeta(DOMXPath $xp, string $name): string
    {
        $nodes = $xp->query("//meta[@name='$name']/@content");
        return $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
    }

    private function getOgTag(DOMXPath $xp, string $property): string
    {
        $nodes = $xp->query("//meta[@property='$property']/@content");
        if (!$nodes->length) {
            $nodes = $xp->query("//meta[@name='$property']/@content");
        }
        return $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
    }

    private function getCanonical(DOMXPath $xp): string
    {
        $nodes = $xp->query("//link[@rel='canonical']/@href");
        return $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
    }

    private function getFirstText(DOMXPath $xp, string $query): string
    {
        $nodes = $xp->query($query);
        return $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    private function getAllText(DOMXPath $xp, string $query, int $limit): array
    {
        $result = [];
        foreach ($xp->query($query) as $node) {
            $text = trim($node->textContent);
            if ($text) $result[] = $text;
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    private function extractBodyText(DOMXPath $xp): string
    {
        // nav, header, footer, script, style entfernen
        foreach ($xp->query('//nav|//footer|//script|//style|//header') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $body = $xp->query('//body');
        return $body->length ? preg_replace('/\s+/', ' ', strip_tags($body->item(0)->textContent)) : '';
    }

    private function guessPageType(array $jsonld, string $url, string $h1): string
    {
        foreach ($jsonld as $ld) {
            $type = $ld['@type'] ?? '';
            if (in_array($type, ['Article', 'BlogPosting', 'NewsArticle'])) return 'article';
            if ($type === 'Product') return 'product';
            if ($type === 'WebSite') return 'website';
            if (in_array($type, ['LocalBusiness', 'Organization'])) return 'organization';
        }
        if (preg_match('#/blog/|/artikel/|/news/|/post/#i', $url)) return 'article';
        if (preg_match('#/produkt|/product|/shop#i', $url)) return 'product';
        return 'website';
    }
}
