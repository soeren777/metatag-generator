<?php

if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

set_time_limit(0);

$jobId = $argv[1] ?? null;
if (!$jobId) exit('Missing job ID');

$jobDir   = '/tmp/metatag-jobs';
$jobFile  = "$jobDir/$jobId.json";
$dataFile = "$jobDir/$jobId.input.json";

if (!file_exists($dataFile)) {
    file_put_contents($jobFile, json_encode(['status' => 'error', 'message' => 'Input data missing', 'updated' => time()]), LOCK_EX);
    exit;
}

$input     = json_decode(file_get_contents($dataFile), true);
$page      = $input['page']      ?? null;
$overrides = $input['overrides'] ?? [];

@unlink($dataFile);

if (!$page) {
    file_put_contents($jobFile, json_encode(['status' => 'error', 'message' => 'Page data missing', 'updated' => time()]), LOCK_EX);
    exit;
}

$dir = __DIR__;
require_once "$dir/config.php";
require_once "$dir/Generator.php";
require_once "$dir/providers/ProviderFactory.php";

$config    = require "$dir/config.php";
$provider  = ProviderFactory::create($config);
$generator = new MetaTagGenerator($provider);

// Retry on 503/429
$result      = null;
$lastError   = null;
$retryDelays = [2, 5, 10];

for ($attempt = 0; $attempt < 3; $attempt++) {
    try {
        $result = $generator->generate($page, $overrides);
        break;
    } catch (Throwable $e) {
        $lastError = $e;
        $msg = $e->getMessage();
        if ($attempt < 2 && (str_contains($msg, '503') || str_contains($msg, '429'))) {
            sleep($retryDelays[$attempt]);
            continue;
        }
        break;
    }
}

if ($result === null) {
    file_put_contents($jobFile, json_encode([
        'status'  => 'error',
        'message' => $lastError?->getMessage() ?? 'Unknown error',
        'updated' => time(),
    ]), LOCK_EX);
    exit;
}

file_put_contents($jobFile, json_encode([
    'status'  => 'done',
    'result'  => $result,
    'updated' => time(),
]), LOCK_EX);
