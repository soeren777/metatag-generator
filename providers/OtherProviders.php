<?php

require_once __DIR__ . '/BaseHttpProvider.php';

// ── Perplexity ────────────────────────────────────────────────────────────────
class PerplexityProvider extends BaseHttpProvider
{
    protected function defaultModel(): string { return 'llama-3.1-sonar-large-128k-online'; }
    public function getName(): string { return 'Perplexity'; }

    protected function buildRequest(string $prompt): array
    {
        return [
            'https://api.perplexity.ai/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            [
                'model'    => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ],
        ];
    }

    protected function extractText(array $r): string
    {
        return $r['choices'][0]['message']['content'] ?? '';
    }
}

// ── xAI Grok ─────────────────────────────────────────────────────────────────
class GrokProvider extends BaseHttpProvider
{
    protected function defaultModel(): string { return 'grok-beta'; }
    public function getName(): string { return 'Grok (xAI)'; }

    protected function buildRequest(string $prompt): array
    {
        return [
            'https://api.x.ai/v1/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            [
                'model'    => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ],
        ];
    }

    protected function extractText(array $r): string
    {
        return $r['choices'][0]['message']['content'] ?? '';
    }
}

// ── OwnAI / custom OpenAI-kompatibler Endpoint ───────────────────────────────
class OwnAiProvider extends BaseHttpProvider
{
    private string $apiUrl;

    public function __construct(string $apiKey, string $model = '', string $apiUrl = '')
    {
        parent::__construct($apiKey, $model);
        $this->apiUrl = $apiUrl;
    }

    protected function defaultModel(): string { return 'default'; }
    public function getName(): string { return 'OwnAI (Custom)'; }

    protected function buildRequest(string $prompt): array
    {
        if (!$this->apiUrl) throw new RuntimeException('OwnAI: ai_api_url nicht konfiguriert');
        return [
            $this->apiUrl,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            [
                'model'    => $this->model,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ],
        ];
    }

    protected function extractText(array $r): string
    {
        return $r['choices'][0]['message']['content'] ?? '';
    }
}
