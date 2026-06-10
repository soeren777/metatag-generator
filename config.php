<?php

$env = [];
foreach (file('/home/lighttpd/soerenmeier.de/.env') as $line) {
    $line = trim($line);
    if (!$line || str_starts_with($line, '#')) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) continue;
    $env[trim($parts[0])] = trim($parts[1], " \t\n\r\0\x0B\"'");
}

return [

    // ── AI-Provider ───────────────────────────────────────────────────────────
    // 'none'        → regelbasierte Generierung (kostenlos, kein Key nötig)
    // 'anthropic'   → Claude
    // 'openai'      → GPT-4o
    // 'google'      → Gemini
    // 'perplexity'  → Perplexity API
    // 'grok'        → xAI Grok
    // 'ownai'       → Beliebiger OpenAI-kompatibler Endpoint
    'ai_provider' => $env['METATAG_AI_PROVIDER'] ?? 'none',
    'ai_api_key'  => $env['METATAG_AI_KEY']      ?? '',
    'ai_model'    => $env['METATAG_AI_MODEL']     ?? '',
    'ai_api_url'  => $env['METATAG_AI_URL']       ?? '',

    // ── Crawler ───────────────────────────────────────────────────────────────
    'crawler_timeout'    => 10,
    'crawler_user_agent' => 'MetaTagGenerator/1.0 (+https://soerenmeier.de/webtools/metatag-generator/)',
    'max_content_length' => 50000,

    // ── Rate Limiting ─────────────────────────────────────────────────────────
    'rate_limit_requests' => 10,
    'rate_limit_window'   => 60,
    'rate_limit_dir'      => __DIR__ . '/tmp',

    // ── Multicrawl ────────────────────────────────────────────────────────────
    'multicrawl_max_urls'  => 250,
    'multicrawl_delay_ms'  => 200,

];
