<?php

require_once __DIR__ . '/BaseHttpProvider.php';

class AnthropicProvider extends BaseHttpProvider
{
    protected function defaultModel(): string { return 'claude-sonnet-4-5'; }
    public function getName(): string { return 'Claude (Anthropic)'; }

    protected function buildRequest(string $prompt): array
    {
        return [
            'https://api.anthropic.com/v1/messages',
            [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            [
                'model'      => $this->model,
                'max_tokens' => 1024,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ],
        ];
    }

    protected function extractText(array $r): string
    {
        return $r['content'][0]['text'] ?? '';
    }
}
