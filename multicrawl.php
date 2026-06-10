<?php

// SSE – disable output buffering completely
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
@ini_set('implicit_flush', true);
ob_implicit_flush(true);
while (ob_get_level()) ob_end_clean();

// No time limit – multicrawl can run long
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');

// ── Helpers ───────────────────────────────────────────────────────────────────

function sse(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

function sseError(string $message): void
{
    sse('error', ['message' => $message]);
}

// ── Input ─────────────────────────────────────────────────────────────────────

$url = trim($_GET['url'] ?? '');

if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    sseError('Invalid URL');
    exit;
}

$scheme = parse_url($url, PHP_URL_SCHEME);
if (!in_array($scheme, ['http', 'https'])) {
    sseError('Only HTTP/HTTPS allowed');
    exit;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Crawler.php';
require_once __DIR__ . '/Generator.php';
require_once __DIR__ . '/SitemapExtractor.php';
require_once __DIR__ . '/providers/ProviderFactory.php';

$config    = require __DIR__ . '/config.php';
$extractor = new SitemapExtractor($config);
$crawler   = new Crawler($config);
$provider  = ProviderFactory::create($config);
$generator = new MetaTagGenerator($provider);
$delayMs   = (int)($config['multicrawl_delay_ms'] ?? 500);

// ── Step 1: Collect URLs ──────────────────────────────────────────────────────

sse('status', ['message' => 'Collecting URLs…', 'phase' => 'collecting']);

try {
    $urls = $extractor->getUrls($url);
} catch (Throwable $e) {
    sseError('URL collection failed: ' . $e->getMessage());
    exit;
}

if (empty($urls)) {
    sseError('No URLs found for this domain');
    exit;
}

sse('urls_found', ['count' => count($urls), 'urls' => $urls]);

// ── Step 2: Process each URL ──────────────────────────────────────────────────

$results  = [];
$total    = count($urls);
$done     = 0;
$errors   = 0;

foreach ($urls as $pageUrl) {
    $done++;

    // Heartbeat to keep connection alive
    echo ": heartbeat\n\n";
    flush();

    sse('progress', [
        'current' => $done,
        'total'   => $total,
        'url'     => $pageUrl,
        'phase'   => 'crawling',
    ]);

    try {
        // Crawl
        $page = $crawler->fetch($pageUrl);

        sse('progress', [
            'current' => $done,
            'total'   => $total,
            'url'     => $pageUrl,
            'phase'   => 'generating',
        ]);

        // Generate with retry on 503
        $result    = null;
        $lastError = null;
        $retries   = 3;
        $retryDelays = [2000, 5000, 10000]; // ms

        for ($attempt = 0; $attempt < $retries; $attempt++) {
            try {
                // Heartbeat before potentially long AI call
                echo ": heartbeat\n\n";
                flush();
                $result = $generator->generate($page);
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                $msg = $e->getMessage();
                // Only retry on 503/429
                if ($attempt < $retries - 1 && (str_contains($msg, '503') || str_contains($msg, '429'))) {
                    sse('progress', [
                        'current' => $done,
                        'total'   => $total,
                        'url'     => $pageUrl,
                        'phase'   => 'retry',
                        'attempt' => $attempt + 1,
                    ]);
                    usleep($retryDelays[$attempt] * 1000);
                    continue;
                }
                break;
            }
        }

        if ($result === null) {
            throw $lastError;
        }

        $row = [
            'url'         => $pageUrl,
            'title'       => $result['title']       ?? '',
            'description' => $result['description'] ?? '',
            'keywords'    => $result['keywords'] ?? $page['existing']['keywords'] ?? '',
            'og_title'    => $result['og']['og:title'] ?? '',
            'og_desc'     => $result['og']['og:description'] ?? '',
            'robots'      => $result['robots']['standard'] ?? '',
            'ai_powered'  => $result['ai_powered']  ?? false,
            'status'      => 'ok',
        ];

        $results[] = $row;

        sse('result', [
            'current' => $done,
            'total'   => $total,
            'row'     => $row,
        ]);

    } catch (Throwable $e) {
        $errors++;
        $msg = $e->getMessage();
        // Extract HTTP code if present
        preg_match('/\b(4\d\d|5\d\d)\b/', $msg, $m);
        $code = $m[1] ?? 'error';
        $row = [
            'url'       => $pageUrl,
            'status'    => 'error',
            'error'     => $msg,
            'errorCode' => $code,
        ];
        $results[] = $row;

        sse('result', [
            'current' => $done,
            'total'   => $total,
            'row'     => $row,
        ]);
    }

    // Delay between requests
    if ($done < $total && $delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

// ── Step 3: Done ──────────────────────────────────────────────────────────────

sse('done', [
    'total'   => $total,
    'success' => $total - $errors,
    'errors'  => $errors,
]);
