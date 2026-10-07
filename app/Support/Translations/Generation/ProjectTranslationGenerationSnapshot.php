<?php

namespace App\Support\Translations\Generation;

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;

/**
 * The provider-neutral request a batch was planned from, as plain arrays —
 * strings, integers, booleans, null and arrays, nothing a cache has to
 * unserialize as an object.
 *
 * A queued job rebuilds its request from this snapshot rather than from the
 * catalog as it is when the job runs: what is sent is exactly what was
 * planned at the click, so the request is deterministic and still fits the
 * one provider request it was sized for, and nothing a browser sent is in it.
 * The English text is checked against the catalog again, by fingerprint,
 * before anything is sent.
 */
final class ProjectTranslationGenerationSnapshot
{
    /** @return array{id: string, source_locale: ?string, source_text: string, content_type: string, context: ?string, max_length: ?int, multiline: bool, placeholders: list<string>, existing_translations: array<string, string>} */
    public static function item(TranslationItem $item): array
    {
        return [
            'id' => $item->id,
            'source_locale' => $item->sourceLocale,
            'source_text' => $item->sourceText,
            'content_type' => $item->contentType,
            'context' => $item->context,
            'max_length' => $item->maxLength,
            'multiline' => $item->multiline,
            'placeholders' => $item->placeholders,
            'existing_translations' => $item->existingTranslations,
        ];
    }

    /**
     * The request one chunk's items make, rebuilt from their snapshots; built
     * through the engine's own contract, so a snapshot that is not a valid
     * item is refused the same way a fresh one would be.
     *
     * @param  list<array<string, mixed>>  $items  item snapshots
     * @param  array<array-key, mixed>  $glossary
     */
    public static function request(string $targetLocale, string $classification, array $items, array $glossary): TranslationBatchRequest
    {
        return new TranslationBatchRequest(
            $targetLocale,
            TranslationDataClassification::from($classification),
            array_map(self::rebuild(...), $items),
            $glossary,
        );
    }

    /** @param  array<string, mixed>  $snapshot */
    private static function rebuild(array $snapshot): TranslationItem
    {
        return new TranslationItem(
            id: (string) $snapshot['id'],
            sourceLocale: is_string($snapshot['source_locale'] ?? null) ? $snapshot['source_locale'] : null,
            sourceText: (string) $snapshot['source_text'],
            contentType: (string) $snapshot['content_type'],
            multiline: (bool) $snapshot['multiline'],
            context: is_string($snapshot['context'] ?? null) ? $snapshot['context'] : null,
            maxLength: is_int($snapshot['max_length'] ?? null) ? $snapshot['max_length'] : null,
            placeholders: is_array($snapshot['placeholders'] ?? null) ? $snapshot['placeholders'] : [],
            existingTranslations: is_array($snapshot['existing_translations'] ?? null) ? $snapshot['existing_translations'] : [],
        );
    }
}
