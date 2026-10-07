<?php

namespace App\Support\TranslationEngine\Data;

/**
 * What a provider hands back for one request it completed: the translations
 * exactly as the model returned them, and the call that produced them.
 *
 * Internal to the engine, and untrusted. A provider only guarantees the shape
 * — a list of string ids and string texts. Whether every id belongs to the
 * request, appears once, and has a usable translation is decided by
 * TranslationService, the same way for every provider.
 */
final readonly class TranslationProviderResponse
{
    /**
     * @param  list<array{id: string, text: string}>  $translations
     */
    public function __construct(
        public array $translations,
        public TranslationProviderCall $call,
    ) {}
}
