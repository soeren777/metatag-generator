<?php

require_once __DIR__ . '/BaseHttpProvider.php';

class OpenAiProvider extends BaseHttpProvider
{
    private string $apiUrl;

    public function __construct(string $apiKey, string $model = '', string $apiUrl = '')
    {
        parent::__construct($apiKey, $model);
        $this->apiUrl = $apiUrl ?: 'https://api.openai.com/v1/chat/completions';
    }

    protected function defaultModel(): string { return 'gpt-4o'; }
    public function getName(): string { return 'GPT-4o (OpenAI)'; }

    protected function buildRequest(string $prompt): array
    {
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
