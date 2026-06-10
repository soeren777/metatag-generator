<?php

require_once __DIR__ . '/ProviderInterface.php';

class GoogleProvider implements ProviderInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = '')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model ?: 'gemini-1.5-flash';
    }

    public function getName(): string { return 'Gemini (Google)'; }

    public function generate(string $prompt): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT    => 60,
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("cURL error: $err");
        if ($code >= 400) throw new RuntimeException("API error $code: " . substr($raw, 0, 300));

        $r    = json_decode($raw, true);
        $text = $r['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (!$text) throw new RuntimeException("Leere Antwort von Gemini: " . substr($raw, 0, 300));

        return $text;
    }
}
