<?php

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;

/**
 * A request that exists is a valid one: every rule of the engine's request
 * contract is checked when an item or a batch is built, so a consumer's
 * mistake surfaces where it was made — never as a provider call that could not
 * succeed. The logical size limits are configuration and are the service's to
 * hold; see TranslationServiceTest.
 */

// =============================================================================
// Batches
// =============================================================================

it('accepts a batch of one item, the same request type as any other batch', function () {
    $batch = translationBatch([translationItem()]);

    expect($batch->items)->toHaveCount(1)
        ->and($batch->itemIds())->toBe(['categories:17:name'])
        ->and($batch->targetLocale)->toBe('de')
        ->and($batch->dataClassification)->toBe(TranslationDataClassification::PublicContent)
        ->and($batch->glossary)->toBe([]);
});

it('accepts batches of 50 and 500 items', function (int $count) {
    expect(translationBatch(translationItems($count))->items)->toHaveCount($count);
})->with([50, 500]);

it('refuses a batch without items', function () {
    translationBatch([]);
})->throws(InvalidTranslationRequestException::class, 'has no items');

it('refuses items that are not a list of TranslationItem', function (array $items, string $message) {
    expect(fn () => new TranslationBatchRequest('de', TranslationDataClassification::PublicContent, $items))
        ->toThrow(InvalidTranslationRequestException::class, $message);
})->with([
    'keyed' => [['first' => translationItem()], 'not a list'],
    'a string' => [['Dogs'], 'not a TranslationItem'],
    'an array' => [[['id' => 'x', 'source_text' => 'Dogs']], 'not a TranslationItem'],
]);

it('refuses an id used twice in one batch, naming it', function () {
    translationBatch([
        translationItem(['id' => 'post:713:title', 'sourceText' => 'First']),
        translationItem(['id' => 'post:713:title', 'sourceText' => 'Second']),
    ]);
})->throws(InvalidTranslationRequestException::class, 'Translation item [post:713:title] appears more than once in the batch.');

it('refuses a target locale that is not a locale', function (string $locale) {
    translationBatch(targetLocale: $locale);
})->throws(InvalidTranslationRequestException::class, 'target locale')->with([
    'empty' => '',
    'a word' => 'German',
    'one letter' => 'd',
    'a space' => 'de DE',
    'a path' => '../de',
    'trailing separator' => 'de-',
]);

it('accepts target locales with script and region subtags', function (string $locale) {
    expect(translationBatch(targetLocale: $locale)->targetLocale)->toBe($locale);
})->with(['fr', 'pt_BR', 'pt-BR', 'zh-Hant-TW', 'es-419']);

it('refuses an item already in the target locale, whatever the spelling, rather than paying to copy it', function (string $source, string $target) {
    translationBatch([translationItem(['sourceLocale' => $source])], targetLocale: $target);
})->throws(InvalidTranslationRequestException::class, 'is already in the target locale')->with([
    ['de', 'de'],
    ['pt_BR', 'pt-br'],
    ['EN', 'en'],
]);

it('accepts an unknown source locale, to be determined from the text', function () {
    $batch = translationBatch([translationItem(['sourceLocale' => null])], targetLocale: 'en');

    expect($batch->items[0]->sourceLocale)->toBeNull();
});

it('keeps target, classification and glossary when a batch is cut into requests', function () {
    $batch = translationBatch(
        translationItems(3),
        targetLocale: 'bg',
        classification: TranslationDataClassification::PublicUserGenerated,
        glossary: ['RateGuru' => 'RateGuru'],
    );

    $chunk = $batch->withItems([$batch->items[2]]);

    expect($chunk->itemIds())->toBe(['item:3'])
        ->and($chunk->targetLocale)->toBe('bg')
        ->and($chunk->dataClassification)->toBe(TranslationDataClassification::PublicUserGenerated)
        ->and($chunk->glossary)->toBe(['RateGuru' => 'RateGuru']);
});

// =============================================================================
// Glossary
// =============================================================================

it('accepts a glossary of source terms and their required target forms', function () {
    $glossary = ['RateGuru' => 'RateGuru', 'Guru' => 'Guru', 'Post' => 'Beitrag', '2024' => '2024'];

    expect(translationBatch(glossary: $glossary)->glossary)->toBe($glossary);
});

it('refuses a malformed glossary', function (array $glossary) {
    translationBatch(glossary: $glossary);
})->throws(InvalidTranslationRequestException::class, 'glossary')->with([
    'a blank term' => [['  ' => 'Beitrag']],
    'a blank target' => [['Post' => ' ']],
    'a target that is not text' => [['Post' => 42]],
    'a nested target' => [['Post' => ['Beitrag']]],
    'a term that is too long' => [[str_repeat('a', 101) => 'b']],
    'a target that is too long' => [['Post' => str_repeat('b', 101)]],
    'too many terms' => [array_combine(
        array_map(fn (int $n): string => "term-{$n}", range(1, 201)),
        array_map(fn (int $n): string => "Begriff-{$n}", range(1, 201)),
    )],
]);

// =============================================================================
// Items
// =============================================================================

it('builds an item with every field a provider and the checks need', function () {
    $item = translationItem([
        'id' => 'project_settings:contact_line',
        'sourceText' => 'Write to {contact_email}, {name}.',
        'contentType' => 'project_setting.contact_line',
        'multiline' => true,
        'context' => 'Shown in the footer.',
        'maxLength' => 120,
        'placeholders' => ['{contact_email}', '{name}'],
        'existingTranslations' => ['fr' => 'Écrivez à {contact_email}, {name}.', 'pt_BR' => 'Escreva para {contact_email}, {name}.'],
    ]);

    expect($item->id)->toBe('project_settings:contact_line')
        ->and($item->sourceLocale)->toBe('en')
        ->and($item->multiline)->toBeTrue()
        ->and($item->context)->toBe('Shown in the footer.')
        ->and($item->maxLength)->toBe(120)
        ->and($item->placeholders)->toBe(['{contact_email}', '{name}'])
        ->and($item->existingTranslations)->toHaveKeys(['fr', 'pt_BR']);
});

it('refuses an id that is blank, too long, padded or carries invisible characters', function (string $id) {
    translationItem(['id' => $id]);
})->throws(InvalidTranslationRequestException::class, 'A translation item id')->with([
    'empty' => '',
    'blank' => '   ',
    'too long' => str_repeat('a', TranslationItem::MAX_ID_LENGTH + 1),
    'padded' => ' post:1:title',
    'padded with a no-break space' => "\u{00A0}post:1:title",
    'trailing ideographic space' => "post:1:title\u{3000}",
    'a newline' => "post:1\n:title",
    'a tab' => "post:1\t:title",
    'a C1 control (next line)' => "post:1\u{0085}:title",
    'a line separator' => "post:1\u{2028}:title",
    'a paragraph separator' => "post:1\u{2029}:title",
    'a zero-width space' => "post:1\u{200B}:title",
    'a byte order mark' => "\u{FEFF}post:1:title",
    'invalid UTF-8' => "post:\xC3\x28",
]);

it('accepts any opaque id up to the bound, without interpreting it', function (string $id) {
    expect(translationItem(['id' => $id])->id)->toBe($id);
})->with([
    'a record field' => 'categories:17:name',
    'a comment body' => 'comment:9182:body',
    'unicode' => 'страница:о-нас',
    'an inner space' => 'static page:about us',
    'an inner no-break space' => "page:about\u{00A0}us",
    'at the bound' => str_repeat('a', TranslationItem::MAX_ID_LENGTH),
]);

it('refuses a blank source text', function (string $text) {
    translationItem(['sourceText' => $text]);
})->throws(InvalidTranslationRequestException::class, 'has a blank source text')->with([
    'empty' => '',
    'spaces' => '   ',
    'whitespace and newlines' => " \n\t\r\n ",
]);

it('refuses a source text that is not valid UTF-8', function () {
    translationItem(['sourceText' => "Hunde \xC3\x28"]);
})->throws(InvalidTranslationRequestException::class, 'not valid UTF-8');

it('accepts a content type no consumer has used before, without an engine change', function (string $contentType) {
    expect(translationItem(['contentType' => $contentType])->contentType)->toBe($contentType);
})->with([
    'project_setting.site_tagline', 'category.name', 'rating_option.description',
    'post.title', 'post.body', 'comment.body', 'poll-option.label', '2fa.hint',
]);

it('refuses a content type outside its format', function (string $contentType) {
    translationItem(['contentType' => $contentType]);
})->throws(InvalidTranslationRequestException::class, 'content type')->with([
    'empty' => '',
    'uppercase' => 'Category.Name',
    'a space' => 'category name',
    'leading dot' => '.category',
    'a slash' => 'category/name',
    'too long' => str_repeat('a', TranslationItem::MAX_CONTENT_TYPE_LENGTH + 1),
]);

it('treats a blank context as no context', function (?string $context) {
    expect(translationItem(['context' => $context])->context)->toBeNull();
})->with([null, '', '   ']);

it('refuses a context beyond its bound', function () {
    translationItem(['context' => str_repeat('c', TranslationItem::MAX_CONTEXT_LENGTH + 1)]);
})->throws(InvalidTranslationRequestException::class, 'context');

it('refuses a maximum length that is not positive', function (int $maxLength) {
    translationItem(['maxLength' => $maxLength]);
})->throws(InvalidTranslationRequestException::class, 'maximum length')->with([0, -1, -80]);

it('accepts no maximum length, or a positive one', function (?int $maxLength) {
    expect(translationItem(['maxLength' => $maxLength])->maxLength)->toBe($maxLength);
})->with([null, 1, 80, 100_000]);

it('refuses placeholders that are blank, repeated, not text, absent from the source or not a list', function (array $placeholders, string $message) {
    expect(fn () => translationItem(['sourceText' => 'Hello {name}, write to {contact_email}.', 'placeholders' => $placeholders]))
        ->toThrow(InvalidTranslationRequestException::class, $message);
})->with([
    'blank' => [[' '], 'not a non-blank string'],
    'empty' => [[''], 'not a non-blank string'],
    'repeated' => [['{name}', '{name}'], 'lists the placeholder {name} twice'],
    'not text' => [[42], 'not a non-blank string'],
    'not in the source' => [['{missing}'], 'which its source text does not contain'],
    'keyed' => [['a' => '{name}'], 'not a list'],
    'too long' => [['{'.str_repeat('x', TranslationItem::MAX_PLACEHOLDER_LENGTH).'}'], 'longer than'],
]);

it('refuses existing translations that are not locale => non-blank text', function (array $existing, string $message) {
    expect(fn () => translationItem(['existingTranslations' => $existing]))
        ->toThrow(InvalidTranslationRequestException::class, $message);
})->with([
    'a list' => [['Chiens'], 'not a locale'],
    'a word for a key' => [['French' => 'Chiens'], 'not a locale'],
    'null, as storage may hold' => [['fr' => null], 'not a non-blank string'],
    'a number' => [['fr' => 42], 'not a non-blank string'],
    'blank' => [['fr' => '  '], 'not a non-blank string'],
    'nested' => [['fr' => ['Chiens']], 'not a non-blank string'],
]);

it('names the item and never repeats its text in a refusal', function () {
    try {
        translationItem([
            'id' => 'comment:9182:body',
            'sourceText' => 'A private remark that must never reach a log line',
            'placeholders' => ['{absent}'],
        ]);
        $this->fail('the item should have been refused');
    } catch (InvalidTranslationRequestException $exception) {
        expect($exception->getMessage())
            ->toContain('[comment:9182:body]')
            ->not->toContain('private remark');
    }
});
