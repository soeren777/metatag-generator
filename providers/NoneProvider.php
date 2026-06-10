<?php

require_once __DIR__ . '/ProviderInterface.php';

/**
 * Kein AI-Provider – gibt den Prompt unverändert zurück.
 * Die eigentliche regelbasierte Logik liegt in Generator.php.
 * Dieser Provider signalisiert nur, dass keine AI genutzt wird.
 */
class NoneProvider implements ProviderInterface
{
    public function generate(string $prompt): string
    {
        // Wird nie aufgerufen – Generator.php prüft vorher ob Provider = none
        return '';
    }

    public function getName(): string { return 'Regelbasiert'; }
}
