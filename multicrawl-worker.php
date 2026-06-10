<?php

// CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

set_time_limit(0);

$jobId = $argv[1] ?? null;
$url   = $argv[2] ?? null;

if (!$jobId || !$url) {
    exit('Usage: php multicrawl-worker.php JOB_ID URL' . PHP_EOL);
}

$jobDir  = '/tmp/metatag-jobs';
$jobFile = "$jobDir/$jobId.json";

if (!is_dir($jobDir)) mkdir($jobDir, 0700, true);

// ── Job state helpers ─────────────────────────────────────────────────────────

function writeJob(string $file, array $state): void
{
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function readJob(string $file): array
{
    $data = @file_get_contents($file);
    return $data ? json_decode($data, true) : [];
}

// Initial state
writeJob($jobFile, [
    'status'    => 'collecting',
    'total'     => 0,
    'done'      => 0,
    'errors'    => 0,
    'rows'      => [],
    'message'   => 'Collecting URLs…',
    'started'   => time(),
    'updated'   => time(),
]);

// ── Bootstrap ─────────────────────────────────────────────────────────────────

$dir = __DIR__;
require_once "$dir/config.php";
require_once "$dir/Crawler.php";
require_once "$dir/Generator.php";
require_once "$dir/SitemapExtractor.php";
require_once "$dir/providers/ProviderFactory.php";

$config    = require "$dir/config.php";
$extractor = new SitemapExtractor($config);
$crawler   = new Crawler($config);
$provider  = ProviderFactory::create($config);
$generator = new MetaTagGenerator($provider);
$delayMs   = (int)($config['multicrawl_delay_ms'] ?? 200);

// ── Step 1: Collect URLs ──────────────────────────────────────────────────────

try {
    $urls = $extractor->getUrls($url);
} catch (Throwable $e) {
    writeJob($jobFile, [
        'status'  => 'error',
        'message' => 'URL collection failed: ' . $e->getMessage(),
        'updated' => time(),
    ]);
    exit;
}

if (empty($urls)) {
    writeJob($jobFile, [
        'status'  => 'error',
        'message' => 'No URLs found for this domain',
        'updated' => time(),
    ]);
    exit;
}

$total  = count($urls);
$done   = 0;
$errors = 0;
$rows   = [];

writeJob($jobFile, [
    'status'  => 'running',
    'total'   => $total,
    'done'    => 0,
    'errors'  => 0,
    'rows'    => [],
    'message' => "$total URLs found – starting generation…",
    'started' => time(),
    'updated' => time(),
]);

// ── Step 2: Process each URL ──────────────────────────────────────────────────

foreach ($urls as $pageUrl) {
    $done++;

    // Update progress
    $state = readJob($jobFile);
    $state['done']    = $done;
    $state['message'] = "Crawling $done / $total: $pageUrl";
    $state['updated'] = time();
    writeJob($jobFile, $state);

    try {
        $page = $crawler->fetch($pageUrl);

        $state = readJob($jobFile);
        $state['message'] = "Generating $done / $total: $pageUrl";
        $state['updated'] = time();
        writeJob($jobFile, $state);

        // Generate with retry on 503/429
        $result      = null;
        $lastError   = null;
        $retryDelays = [2, 5, 10]; // seconds

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $result = $generator->generate($page);
                break;
            } catch (Throwable $e) {
                $lastError = $e;
                $msg = $e->getMessage();
                if ($attempt < 2 && (str_contains($msg, '503') || str_contains($msg, '429'))) {
                    $state = readJob($jobFile);
                    $state['message'] = "Retry " . ($attempt + 1) . "/3: $pageUrl";
                    $state['updated'] = time();
                    writeJob($jobFile, $state);
                    sleep($retryDelays[$attempt]);
                    continue;
                }
                break;
            }
        }

        if ($result === null) throw $lastError;

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

    } catch (Throwable $e) {
        $errors++;
        $msg = $e->getMessage();
        preg_match('/\b(4\d\d|5\d\d)\b/', $msg, $m);
        $row = [
            'url'       => $pageUrl,
            'status'    => 'error',
            'error'     => $msg,
            'errorCode' => $m[1] ?? 'error',
        ];
    }

    $rows[] = $row;

    // Write updated state with new row
    $state = readJob($jobFile);
    $state['rows']    = $rows;
    $state['done']    = $done;
    $state['errors']  = $errors;
    $state['updated'] = time();
    writeJob($jobFile, $state);

    if ($done < $total && $delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

// ── Step 3: Done ──────────────────────────────────────────────────────────────

writeJob($jobFile, [
    'status'  => 'done',
    'total'   => $total,
    'done'    => $done,
    'errors'  => $errors,
    'rows'    => $rows,
    'message' => 'Complete',
    'updated' => time(),
]);
