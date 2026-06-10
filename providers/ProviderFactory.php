<?php

require_once __DIR__ . '/NoneProvider.php';
require_once __DIR__ . '/AnthropicProvider.php';
require_once __DIR__ . '/OpenAiProvider.php';
require_once __DIR__ . '/GoogleProvider.php';
require_once __DIR__ . '/OtherProviders.php';

class ProviderFactory
{
    public static function create(array $config): ProviderInterface
    {
        $key   = $config['ai_api_key']  ?? '';
        $model = $config['ai_model']    ?? '';
        $url   = $config['ai_api_url']  ?? '';

        return match ($config['ai_provider'] ?? 'none') {
            'anthropic'  => new AnthropicProvider($key, $model),
            'openai'     => new OpenAiProvider($key, $model),
            'google'     => new GoogleProvider($key, $model),
            'perplexity' => new PerplexityProvider($key, $model),
            'grok'       => new GrokProvider($key, $model),
            'ownai'      => new OwnAiProvider($key, $model, $url),
            default      => new NoneProvider(),
        };
    }

    /** Gibt alle konfigurierbaren Provider-Namen zurück */
    public static function available(): array
    {
        return [
            'none'        => 'Regelbasiert (kein AI)',
            'anthropic'   => 'Claude (Anthropic)',
            'openai'      => 'GPT-4o (OpenAI)',
            'google'      => 'Gemini (Google)',
            'perplexity'  => 'Perplexity',
            'grok'        => 'Grok (xAI)',
            'ownai'       => 'OwnAI / Custom Endpoint',
        ];
    }
}
