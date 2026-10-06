<?php

namespace App\Support\TranslationEngine\Data;

use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\TranslationLocale;

/**
 * One text to translate, with everything a provider needs to translate it
 * well and everything the engine needs to check what comes back.
 *
 * An item that exists is a valid one: every rule is checked here, when it is
 * built, so a consumer's mistake surfaces at the line that made it rather than
 * as money spent on a provider call that could never succeed.
 *
 * The engine interprets none of it beyond those rules. The id is the
 * consumer's correlation key and comes back unchanged; the content type is a
 * label for the model, not a switch in the engine — a new consumer brings its
 * own without changing anything here.
 */
final readonly class TranslationItem
{
    public const MAX_ID_LENGTH = 255;

    public const MAX_CONTENT_TYPE_LENGTH = 100;

    public const MAX_CONTEXT_LENGTH = 2000;

    public const MAX_PLACEHOLDER_LENGTH = 100;

    private const CONTENT_TYPE_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    /** Where the text is used, for the model; null when the consumer gives none. */
    public ?string $context;

    /** @var list<string> strings the translation must keep verbatim */
    public array $placeholders;

    /** @var array<string, string> locale => translation, context only */
    public array $existingTranslations;

    /**
     * The two lists arrive as plain arrays and are checked element by element
     * here — they are typically read from storage, where a stored translation
     * is not always text — and only what passes becomes the typed property.
     *
     * @param  string  $id  the consumer's own correlation id, unique in its batch: `categories:17:name`, `comment:9182:body`
     * @param  string|null  $sourceLocale  the language of the source text; null asks the provider to determine it from the text
     * @param  string  $sourceText  the authoritative text to translate
     * @param  string  $contentType  what kind of text it is, as a machine-readable label: `category.name`, `post.body`
     * @param  bool  $multiline  whether the translation may span lines
     * @param  string|null  $context  where the text is used, in a sentence for the model; blank means none
     * @param  int|null  $maxLength  the most characters (code points) the translation may have; null sets no limit
     * @param  array<mixed>  $placeholders  a list of the strings the translation must keep verbatim, each present in the source text: `{name}`
     * @param  array<mixed>  $existingTranslations  locale => non-blank translation, as context only — never a source
     */
    public function __construct(
        public string $id,
        public ?string $sourceLocale,
        public string $sourceText,
        public string $contentType,
        public bool $multiline,
        ?string $context = null,
        public ?int $maxLength = null,
        array $placeholders = [],
        array $existingTranslations = [],
    ) {
        $this->context = $context !== null && trim($context) !== '' ? $context : null;

        $this->assertValidId();
        $this->assertValidSourceLocale();
        $this->assertValidSourceText();
        $this->assertValidContentType();
        $this->assertValidContext();
        $this->assertValidMaxLength();

        $this->placeholders = $this->validPlaceholders($placeholders);
        $this->existingTranslations = $this->validExistingTranslations($existingTranslations);
    }

    private function assertValidId(): void
    {
        if (! mb_check_encoding($this->id, 'UTF-8')) {
            throw InvalidTranslationRequestException::forItemId('must be valid UTF-8');
        }

        if (trim($this->id) === '') {
            throw InvalidTranslationRequestException::forItemId('must not be blank');
        }

        if (mb_strlen($this->id) > self::MAX_ID_LENGTH) {
            throw InvalidTranslationRequestException::forItemId('must be at most '.self::MAX_ID_LENGTH.' characters');
        }

        // A provider copies the id back; one with control characters or
        // padding is one it may not copy exactly.
        if (preg_match(self::CONTROL_CHARACTERS, $this->id) === 1 || $this->id !== trim($this->id)) {
            throw InvalidTranslationRequestException::forItemId('must not contain control characters or surrounding whitespace');
        }
    }

    private function assertValidSourceLocale(): void
    {
        if ($this->sourceLocale !== null && ! TranslationLocale::isValid($this->sourceLocale)) {
            throw InvalidTranslationRequestException::forItem($this->id, 'has a source locale that is not a locale');
        }
    }

    private function assertValidSourceText(): void
    {
        if (! mb_check_encoding($this->sourceText, 'UTF-8')) {
            throw InvalidTranslationRequestException::forItem($this->id, 'has a source text that is not valid UTF-8');
        }

        if (trim($this->sourceText) === '') {
            throw InvalidTranslationRequestException::forItem($this->id, 'has a blank source text');
        }
    }

    private function assertValidContentType(): void
    {
        if (strlen($this->contentType) > self::MAX_CONTENT_TYPE_LENGTH
            || preg_match(self::CONTENT_TYPE_PATTERN, $this->contentType) !== 1) {
            throw InvalidTranslationRequestException::forItem(
                $this->id,
                'has a content type that is not 1–'.self::MAX_CONTENT_TYPE_LENGTH.' characters of a-z, 0-9, dot, underscore and hyphen',
            );
        }
    }

    private function assertValidContext(): void
    {
        if ($this->context === null) {
            return;
        }

        if (! mb_check_encoding($this->context, 'UTF-8') || mb_strlen($this->context) > self::MAX_CONTEXT_LENGTH) {
            throw InvalidTranslationRequestException::forItem($this->id, 'has a context that is not valid UTF-8 of at most '.self::MAX_CONTEXT_LENGTH.' characters');
        }
    }

    private function assertValidMaxLength(): void
    {
        if ($this->maxLength !== null && $this->maxLength < 1) {
            throw InvalidTranslationRequestException::forItem($this->id, 'has a maximum length that is not a positive number');
        }
    }

    /**
     * @param  array<mixed>  $placeholders
     * @return list<string>
     */
    private function validPlaceholders(array $placeholders): array
    {
        if (! array_is_list($placeholders)) {
            throw InvalidTranslationRequestException::forItem($this->id, 'has placeholders that are not a list');
        }

        $valid = [];

        foreach ($placeholders as $placeholder) {
            if (! is_string($placeholder) || trim($placeholder) === '' || ! mb_check_encoding($placeholder, 'UTF-8')) {
                throw InvalidTranslationRequestException::forItem($this->id, 'has a placeholder that is not a non-blank string');
            }

            if (mb_strlen($placeholder) > self::MAX_PLACEHOLDER_LENGTH) {
                throw InvalidTranslationRequestException::forItem($this->id, 'has a placeholder longer than '.self::MAX_PLACEHOLDER_LENGTH.' characters');
            }

            if (in_array($placeholder, $valid, true)) {
                throw InvalidTranslationRequestException::forItem($this->id, "lists the placeholder {$placeholder} twice");
            }

            // A placeholder the source does not have can never be kept, so
            // every translation of it would be refused after it was paid for.
            if (! str_contains($this->sourceText, $placeholder)) {
                throw InvalidTranslationRequestException::forItem($this->id, "lists the placeholder {$placeholder}, which its source text does not contain");
            }

            $valid[] = $placeholder;
        }

        return $valid;
    }

    /**
     * @param  array<mixed>  $existingTranslations
     * @return array<string, string>
     */
    private function validExistingTranslations(array $existingTranslations): array
    {
        $valid = [];

        foreach ($existingTranslations as $locale => $translation) {
            if (! is_string($locale) || ! TranslationLocale::isValid($locale)) {
                throw InvalidTranslationRequestException::forItem($this->id, 'has an existing translation under a key that is not a locale');
            }

            if (! is_string($translation) || trim($translation) === '' || ! mb_check_encoding($translation, 'UTF-8')) {
                throw InvalidTranslationRequestException::forItem($this->id, "has an existing {$locale} translation that is not a non-blank string");
            }

            $valid[$locale] = $translation;
        }

        return $valid;
    }
}
