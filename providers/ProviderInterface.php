<?php

interface ProviderInterface
{
    /**
     * Sendet einen Prompt an den AI-Provider und gibt die Antwort zurück.
     * Wirft eine Exception bei Fehlern.
     */
    public function generate(string $prompt): string;

    /** Gibt den lesbaren Namen des Providers zurück */
    public function getName(): string;
}
