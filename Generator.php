<?php

class MetaTagGenerator
{
    private ProviderInterface $provider;
    private bool $useAi;

    public function __construct(ProviderInterface $provider)
    {
        $this->provider = $provider;
        $this->useAi    = !($provider instanceof NoneProvider);
    }

    public function generate(array $page, array $overrides = []): array
    {
        if ($this->useAi) {
            return $this->generateWithAi($page, $overrides);
        }
        return $this->generateRuleBased($page, $overrides);
    }

    // ── Regelbasiert ──────────────────────────────────────────────────────────

    private function generateRuleBased(array $page, array $overrides): array
    {
        $title    = $overrides['title']       ?? $page['h1'] ?? $page['existing']['title'] ?? $page['domain'];
        $keywords = $overrides['keywords']    ?? $page['existing']['keywords'] ?? '';
        $desc     = $overrides['description'] ?? '';

        $optTitle = $this->optimizeTitle($title, $page['domain']);

        if (!$desc && !empty($page['paragraphs'])) {
            $desc = $this->buildDescription($page['paragraphs'][0]);
        } elseif (!$desc) {
            $desc = $this->buildDescription($page['body_text']);
        }
        $optDesc = $this->trimToLength($desc, 155);

        $url    = $page['url'];
        $domain = $page['domain'];
        $type   = $page['page_type'];

        return [
            'provider'   => $this->provider->getName(),
            'ai_powered' => false,
            'title'      => $optTitle,
            'description'=> $optDesc,
            'keywords'   => $overrides['keywords'] ?? $page['existing']['keywords'] ?? '',
            'og'         => $this->buildOg($optTitle, $optDesc, $url, $type, $page),
            'twitter'    => $this->buildTwitter($optTitle, $optDesc),
            'jsonld'     => $this->buildJsonLd($optTitle, $optDesc, $url, $domain, $type),
            'robots'     => $this->buildRobots(),
            'llms_txt'   => $this->buildLlmsTxt($optTitle, $optDesc, $url, $page),
        ];
    }

    private function optimizeTitle(string $raw, string $domain): string
    {
        $raw = preg_replace('/\s*[|\-–]\s*[^|\-–]+$/', '', $raw);
        $raw = trim($raw);
        if (mb_strlen($raw) > 60) {
            $raw = mb_substr($raw, 0, 57) . '…';
        }
        if (mb_strlen($raw) < 30) {
            $suffix = ucfirst(str_replace(['.de', '.com', '.net', '.org'], '', $domain));
            $raw   .= ' – ' . $suffix;
        }
        return $raw;
    }

    private function buildDescription(string $text): string
    {
        $text      = preg_replace('/\s+/', ' ', strip_tags($text));
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $result    = '';
        foreach ($sentences as $s) {
            if (mb_strlen($result . ' ' . $s) > 160) break;
            $result .= ' ' . $s;
        }
        return trim($result) ?: mb_substr($text, 0, 155);
    }

    private function trimToLength(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;

        $cut = mb_substr($text, 0, $max);

        // 1. Try to cut at the last sentence end (. ! ?) – cleanest result
        $best = null;
        foreach (['.', '!', '?'] as $punct) {
            $pos = mb_strrpos($cut, $punct);
            if ($pos !== false && $pos > $max * 0.5) {
                $best = max($best ?? 0, $pos + 1);
            }
        }
        if ($best !== null) {
            return trim(mb_substr($text, 0, $best));
        }

        // 2. Try to cut at the last natural delimiter (| – - : ,)
        foreach (['|', '–', '—', ':', ' - ', ','] as $delim) {
            $pos = mb_strrpos($cut, $delim);
            if ($pos !== false && $pos > $max * 0.4) {
                return rtrim(mb_substr($text, 0, $pos));
            }
        }

        // 3. Fallback: cut at last word boundary, drop dangling stopwords, add …
        $limit = $max - 1;
        $cut   = mb_substr($text, 0, $limit);

        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }
        $cut = rtrim($cut, " .,;:-–—");

        $stopwords = [
            'seit','für','mit','und','oder','von','zu','zur','zum','bei','der','die',
            'das','den','dem','des','ein','eine','einer','einen','im','am','an','auf',
            'als','wie','aus','nach','vor','über','unter','durch','ohne','gegen','um',
            'since','for','with','and','or','of','to','the','a','an','at','on','in',
            'as','by','from','into','about','than','that','this',
        ];

        while (true) {
            $lastSpace = mb_strrpos($cut, ' ');
            if ($lastSpace === false) break;
            $lastWord = mb_strtolower(mb_substr($cut, $lastSpace + 1));
            if (in_array($lastWord, $stopwords, true)) {
                $cut = rtrim(mb_substr($cut, 0, $lastSpace), " .,;:-–—");
                continue;
            }
            break;
        }

        return $cut . '…';
    }

    private function buildOg(string $title, string $desc, string $url, string $type, array $page): array
    {
        return [
            'og:title'       => $title,
            'og:description' => $desc,
            'og:url'         => $url,
            'og:type'        => $type === 'article' ? 'article' : 'website',
            'og:locale'      => 'de_DE',
            'og:image'       => $page['existing']['og_image'] ?? '',
        ];
    }

    private function buildTwitter(string $title, string $desc): array
    {
        return [
            'twitter:card'        => 'summary_large_image',
            'twitter:title'       => $title,
            'twitter:description' => $desc,
        ];
    }

    private function buildJsonLd(string $title, string $desc, string $url, string $domain, string $type): array
    {
        $base = [
            '@context'    => 'https://schema.org',
            'url'         => $url,
            'name'        => $title,
            'description' => $desc,
        ];

        return match($type) {
            'article' => array_merge($base, [
                '@type'     => 'Article',
                'headline'  => $title,
                'publisher' => ['@type' => 'Organization', 'name' => $domain],
            ]),
            'product' => array_merge($base, [
                '@type' => 'Product',
            ]),
            'organization' => array_merge($base, [
                '@type' => 'Organization',
                'name'  => $domain,
            ]),
            default => array_merge($base, [
                '@type'    => 'WebPage',
                'isPartOf' => ['@type' => 'WebSite', 'url' => 'https://' . $domain],
            ]),
        };
    }

    private function buildRobots(): array
    {
        return [
            'standard' => 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
            'ai_open'  => 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
            'ai_block' => 'index, follow, noai, noimageai',
        ];
    }

    private function buildLlmsTxt(string $title, string $desc, string $url, array $page): string
    {
        $lines = ["# $title", '', $desc, ''];
        if (!empty($page['h2s'])) {
            $lines[] = '## Themen';
            foreach ($page['h2s'] as $h2) {
                $lines[] = "- $h2";
            }
            $lines[] = '';
        }
        $lines[] = '## Seiten';
        $lines[] = "- [$title]($url)";
        return implode("\n", $lines);
    }

    // ── AI-gestützt ───────────────────────────────────────────────────────────

    private function generateWithAi(array $page, array $overrides): array
    {
        $prompt = $this->buildPrompt($page, $overrides);
        $raw    = $this->provider->generate($prompt);

        preg_match('/\{[\s\S]+\}/m', $raw, $m);
        $data = $m ? json_decode($m[0], true) : null;

        if (!$data) {
            $result = $this->generateRuleBased($page, $overrides);
            $result['ai_error'] = 'AI-Antwort konnte nicht verarbeitet werden – regelbasierter Fallback.';
            return $result;
        }

        $title = $data['title'] ?? '';
        $desc  = $data['description'] ?? '';
        $kw    = $data['keywords'] ?? '';

        // Sicherheitsnetz: AI hält Längenvorgaben nicht immer ein
        $title = $this->trimToLength($title, 60);
        $desc  = $this->trimToLength($desc, 160);

        return [
            'provider'       => $this->provider->getName(),
            'ai_powered'     => true,
            'title'          => $title,
            'description'    => $desc,
            'keywords'       => $kw,
            'og'             => $this->buildOg($title, $desc, $page['url'], $page['page_type'], $page),
            'twitter'        => $this->buildTwitter($title, $desc),
            'jsonld'         => $this->buildJsonLd($title, $desc, $page['url'], $page['domain'], $page['page_type']),
            'robots'         => $this->buildRobots(),
            'llms_txt'       => $data['llms_txt'] ?? $this->buildLlmsTxt($title, $desc, $page['url'], $page),
            'ai_suggestions' => $data['suggestions'] ?? [],
        ];
    }

    private function buildPrompt(array $page, array $overrides): string
    {
        $content   = mb_substr($page['body_text'], 0, 4000);
        $h1        = $page['h1'];
        $h2s       = implode(', ', array_slice($page['h2s'] ?? [], 0, 8));
        $url       = $page['url'];
        $type      = $page['page_type'];
        $existingKw = $page['existing']['keywords'] ?? '';
        $manualKw  = $overrides['keywords'] ?? '';

        $kwHint = $manualKw
            ? "Focus keywords (user-defined): $manualKw"
            : ($existingKw ? "Existing keywords for reference (may be improved): $existingKw" : '');

        return <<<PROMPT
You are an expert SEO consultant. Analyze the actual page content below and generate optimized meta tags based on what the page is REALLY about — not just based on existing metadata.

URL: $url
Page type: $type
H1: $h1
H2 headings: $h2s
$kwHint

Full page content:
$content

Generate meta tags that accurately reflect the page content. The title and description must be derived from the actual content, not just reworded from existing tags.

CRITICAL LENGTH REQUIREMENTS — these are HARD LIMITS, not guidelines:
- title: MUST be between 50 and 60 characters TOTAL (including spaces and punctuation). Never exceed 60 characters. Never go below 50 characters. Count the characters before responding. If your draft is too long, shorten it by removing less important words — do not just truncate mid-word.
- description: MUST be between 150 and 160 characters TOTAL (including spaces and punctuation). Never exceed 160 characters. Never go below 150 characters. Count the characters before responding. If your draft is too long, rewrite it more concisely — do not just truncate mid-sentence.

Example of correct length (57 chars): "Handmade Leather Wallets – Free Shipping | LeatherCraft"
Example of correct length (158 chars): "Discover handcrafted leather wallets made from premium full-grain leather. Free shipping on all orders over €50. Shop now and save 10%!"

Other rules:
- keywords: 8-12 comma-separated keywords derived from the actual page content
- llms_txt: a complete llms.txt section for this page in markdown
- suggestions: exactly 3 specific SEO improvement tips based on what is missing or weak on this page, in the same language as the page content

Before responding, verify: does "title" have between 50 and 60 characters? Does "description" have between 150 and 160 characters? If not, revise until both constraints are met.

You MUST respond with ONLY a raw JSON object. No markdown, no code fences, no text before or after:

{"title":"...","description":"...","keywords":"...","llms_txt":"...","suggestions":["...","...","..."]}
PROMPT;
    }
}
