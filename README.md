# Meta Tag Generator

A self-hosted PHP tool that crawls websites and generates SEO-optimised meta tags using AI – or a fast rule-based fallback at no cost.

## Features

- **Single URL mode** – crawl one page and generate meta tags instantly
- **Multiple URLs mode** – automatically discover all URLs via sitemap.xml, then generate meta tags for every page. Uses a background job queue with live progress polling – no HTTP timeouts, works on any standard PHP setup
- **7 AI providers** – Claude (Anthropic), GPT-4o (OpenAI), Gemini (Google), Perplexity, Grok (xAI), any OpenAI-compatible endpoint, or free rule-based generation
- **Structured output** – Title, Meta Description, Keywords, Open Graph, Twitter Card, JSON-LD, Robots directive
- **Retry logic** – automatic retry on 503/429 responses (3 attempts, 2s/5s/10s delays)
- **Rate limiting** – file-based per-IP rate limiting
- **No dependencies** – pure PHP, no Composer packages required for core functionality

## Requirements

- PHP 8.1+
- Extensions: `curl`, `dom`, `mbstring`
- PHP CLI access (for background worker process)
- `nohup` available on the server
- Lighttpd, Apache or Nginx with PHP-FPM
- Write access to `tmp/` directory

## Installation

```bash
git clone https://github.com/soeren777/metatag-generator.git
cd metatag-generator
cp .env.example .env
chmod 777 tmp/
```

Edit `.env` with your settings (see Configuration below).

## Configuration

Copy `.env.example` to `.env` and set your values:

```env
METATAG_AI_PROVIDER=google
METATAG_AI_KEY=your-api-key-here
METATAG_AI_MODEL=gemini-2.5-flash
METATAG_AI_URL=
```

### Supported providers

| Provider | Value | Notes |
|----------|-------|-------|
| Rule-based (free) | `none` | No API key required |
| Google Gemini | `google` | Recommended – generous free tier |
| Anthropic Claude | `anthropic` | claude-sonnet-4-6 or similar |
| OpenAI | `openai` | gpt-4o |
| Perplexity | `perplexity` | sonar model |
| xAI Grok | `grok` | |
| Custom endpoint | `ownai` | Any OpenAI-compatible API |

### Additional config options (`config.php`)

```php
'crawler_timeout'     => 10,     // seconds per request
'max_content_length'  => 50000,  // characters passed to AI
'rate_limit_requests' => 10,     // max requests per window
'rate_limit_window'   => 60,     // window in seconds
'multicrawl_max_urls' => 500,    // max URLs per crawl run
'multicrawl_delay_ms' => 200,    // delay between requests (ms)
```

## File structure

```
metatag-generator/
├── index.html                  # Frontend (Single URL + Crawl multiple URLs)
├── api.php                     # API endpoint (single URL + start crawl job)
├── generate-worker.php         # Background CLI worker for Single URL generation
├── multicrawl.php              # Legacy SSE endpoint (superseded by job queue)
├── multicrawl-worker.php       # Background CLI worker for crawl multiple URLs
├── job-status.php              # Job progress endpoint (polled every 2s by frontend)
├── Crawler.php                 # HTTP crawler + HTML parser
├── Generator.php               # Meta tag generator (AI + rule-based, with post-processing)
├── SitemapExtractor.php        # Sitemap discovery + URL extraction
├── config.php                  # Configuration (reads from .env)
├── providers/
│   ├── ProviderFactory.php
│   ├── ProviderInterface.php
│   ├── NoneProvider.php
│   ├── AnthropicProvider.php
│   ├── OpenAIProvider.php
│   ├── GoogleProvider.php
│   ├── PerplexityProvider.php
│   ├── GrokProvider.php
│   └── OwnAIProvider.php
├── tmp/                        # Rate limiting temp files (auto-created)
│   └── .gitkeep
├── .env                        # Secrets (not committed)
├── .env.example                # Example env file
└── .gitignore
```

## How it works

### Generator.php – length enforcement

The AI prompt specifies title (50–60 chars) and description (150–160 chars) as **hard limits**, not guidelines, including concrete examples and a self-check instruction. Despite this, LLMs occasionally exceed the limits since they think in tokens, not characters.

As a safety net, every generated title and description runs through `trimToLength()` regardless of AI compliance. The three-step logic:

1. **Sentence boundary** – if a sentence end (`.` `!` `?`) falls past the halfway point of the limit, cut there. No ellipsis needed.
2. **Natural separator** – if no sentence end, cut at the last `|`, `–`, `—`, `:` or `,` before the limit. No ellipsis.
3. **Word boundary + stopword cleanup** – cut at the last complete word, then strip trailing stopwords (German/English prepositions, articles, conjunctions such as "und", "für", "seit", "the", "of"), then append "…".

### Single URL
1. Frontend sends URL to `api.php` (action: `crawl`)
2. `Crawler.php` fetches the page via cURL and parses HTML (title, H1, H2s, paragraphs, existing meta tags, JSON-LD, page type)
3. Frontend sends parsed data back to `api.php` (action: `generate`)
4. `api.php` starts a background worker via `nohup` – `api.php` returns immediately once the worker is running, eliminating any timeout risk from slow AI responses or 503 retries
5. `Generator.php` sends extracted content to the configured AI provider (inside the worker)
6. Frontend polls for the result and renders it once complete

### Multiple URLs
1. Frontend sends domain to `api.php` (action: `start_multicrawl`)
2. `api.php` creates a job ID, writes initial state to `/tmp/metatag-jobs/JOB_ID.json`, and starts `multicrawl-worker.php` as a background process via `nohup`
3. `multicrawl-worker.php` runs independently of the web server:
   - `SitemapExtractor.php` discovers all URLs: tries `sitemap.xml` → `sitemap_index.xml` → `robots.txt` Sitemap directive → internal link extraction fallback
   - Each URL is crawled and processed sequentially
   - Results written to the job file after every URL
4. Frontend polls `job-status.php?id=JOB_ID` every 2 seconds for live progress
5. On completion: results table with URL, title, description, keywords, status
6. Click any row to open a detail modal with copy buttons per field
7. CSV export available on completion

## Architecture: why a job queue instead of SSE

Early versions used Server-Sent Events (SSE) over a long-running HTTP request. This caused timeouts on sites with 20+ pages because web servers enforce request time limits.

The current architecture uses a background worker for both modes:

```
# Old approach (blocking HTTP request – timeout risk)
Browser → POST api.php → wait for Gemini → response (could timeout via Cloudflare/nginx)

# Current approach (job queue – both Single URL and Multiple URLs)
Browser → POST api.php → nohup php worker.php JOB_ID URL &  (api.php returns immediately)
Browser → GET job-status.php?id=X every 2s → /tmp/metatag-jobs/X.json
```

The worker runs as a separate PHP CLI process, completely independent of the web server and its timeout settings. `api.php` responds as soon as the worker starts – no waiting for slow AI responses or 503 retries. Job state is stored in `/tmp/metatag-jobs/` (RAM-based tmpfs on Linux – fast, auto-cleared on reboot).

> **Note:** On a Pi 5 with normal traffic, the theoretical risk of a delayed `nohup` start under extreme load is not a realistic scenario.

## Server configuration (recommended)

### Lighttpd timeouts (`/etc/lighttpd/lighttpd.conf`)
```
server.max-keep-alive-idle = 300
server.max-read-idle = 300
server.max-write-idle = 300
```

### FastCGI (`/etc/lighttpd/mod_fastcgi.conf`)
```
"idle-timeout" => 600,
"bin-environment" => (
    "PHP_FCGI_MAX_REQUESTS" => "0",
    "PHP_FCGI_CHILDREN" => "4"
)
```

### PHP (`/etc/php82/php.ini`)
```
max_execution_time = 300
```

### PHP CLI path
The worker is started via `nohup php ...`. If your PHP binary is not in `$PATH`, update the path in `api.php`:
```php
exec("nohup /usr/bin/php82 $workerPath $jobArg $urlArg > /dev/null 2>&1 &");
```

## Rate limiting

The tool uses simple file-based rate limiting per IP address. Files are stored in `tmp/` and cleaned up automatically. Default: 10 requests per 60 seconds.

## Privacy & self-hosting

- No data is stored permanently – job files in `/tmp/` are auto-deleted after 1 hour
- Page content is sent to the configured AI provider's API
- The `.env` file must be outside the webroot or blocked via server config

## Roadmap

- [ ] Batch size configuration
- [ ] Docker container

## License

MIT License – free to use, modify and distribute.

## Author

Sören Meier – [soerenmeier.de](https://soerenmeier.de)
