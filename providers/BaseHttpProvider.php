<?php

require_once __DIR__ . '/ProviderInterface.php';

abstract class BaseHttpProvider implements ProviderInterface
{
    protected string $apiKey;
    protected string $model;

    public function __construct(string $apiKey, string $model = '')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model ?: $this->defaultModel();
    }

    abstract protected function defaultModel(): string;
    abstract protected function buildRequest(string $prompt): array; // [url, headers[], body_json]

    public function generate(string $prompt): string
    {
        [$url, $headers, $body] = $this->buildRequest($prompt);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("cURL error: $err");
        if ($code >= 400) throw new RuntimeException("API error $code: " . substr($raw, 0, 300));

        return $this->extractText(json_decode($raw, true));
    }

    abstract protected function extractText(array $response): string;
}
