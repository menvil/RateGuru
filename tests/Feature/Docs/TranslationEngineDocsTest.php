<?php

/**
 * The translation engine's architecture note: the decisions a later consumer
 * has to build on, written down where the code points to them.
 */
function translationEngineDoc(): string
{
    return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(base_path('docs/architecture/translation-engine.md')));
}

it('files the translation engine architecture note', function () {
    expect(base_path('docs/architecture/translation-engine.md'))->toBeFile();
});

it('describes the path from consumer to provider, and storage as outside it', function () {
    expect(translationEngineDoc())
        ->toContain('TranslationBatchRequest(items[])')
        ->toContain('TranslationService')
        ->toContain('TranslationProviderRouter')
        ->toContain('OpenAiTranslationProvider')
        ->toContain('Translation engine ≠ translation storage')
        ->toContain('a local HTTP provider');
});

it('records the batch contract and both sizes of batch', function () {
    expect(translationEngineDoc())
        ->toContain('One text is a batch of one item')
        ->toContain('| Logical batch — one `translate()` call | 1–500 | ≤ 500,000 |')
        ->toContain('| OpenAI request — one HTTP call | ≤ 50 | ≤ 60,000 |')
        ->toContain('is **never cut**')
        ->toContain('not its asynchronous Batch API');
});

it('records the classifications, the glossary and existing translations as context', function () {
    expect(translationEngineDoc())
        ->toContain('`public_content`')
        ->toContain('`public_user_generated`')
        ->toContain('`private_content`')
        ->toContain('refused **before** any request')
        ->toContain('a term mapped to itself (`RateGuru => RateGuru`) stays untranslated')
        ->toContain('`existing_translations` are marked as context only');
});

it('records what the engine deliberately does not do', function () {
    expect(translationEngineDoc())
        ->toContain('**No storage.**')
        ->toContain('no Redis')
        ->toContain('**No queue.**')
        ->toContain('**No fallback.**')
        ->toContain('**No retry.**')
        ->toContain('Structured Output constrains the shape; `TranslationService` still checks everything');
});

it('keeps the API key on the target host only, and CI off the network', function () {
    expect(translationEngineDoc())
        ->toContain("The API key lives **only in the target host's canonical shared `.env`**")
        ->toContain('never a GitHub Environment secret')
        ->toContain('No test reaches OpenAI')
        ->toContain('`Http::fake()`');
});
