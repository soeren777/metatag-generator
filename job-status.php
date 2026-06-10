<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$jobId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');

if (!$jobId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job ID']);
    exit;
}

$jobFile = "/tmp/metatag-jobs/$jobId.json";

if (!file_exists($jobFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found']);
    exit;
}

$data = json_decode(file_get_contents($jobFile), true);

// Clean up completed jobs older than 1 hour
if (isset($data['updated']) && time() - $data['updated'] > 3600) {
    @unlink($jobFile);
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
