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

it('records Translation Center as the first consumer, through the project adapter and a batch of one', function () {
    expect(translationEngineDoc())
        ->toContain('## First consumer: Translation Center')
        ->toContain('→ ProjectTranslationRequestFactory')
        ->toContain('→ TranslationBatchRequest(items: [unit])')
        ->toContain('The translation engine does not know `ProjectTranslationUnit`.')
        ->toContain('stores nothing; neither does the engine')
        ->toContain('**Bulk generation reuses it.**');
});

it('records Generate missing as a second consumer that keeps its drafts and queue outside the engine', function () {
    expect(translationEngineDoc())
        ->toContain('## Second consumer: Generate missing')
        ->toContain('→ ProjectTranslationGenerationPlanner')
        ->toContain('→ GenerateProjectTranslationChunkJob × chunks')
        ->toContain('Translation engine ≠ translation storage ≠ draft storage')
        ->toContain('**One job, at most one paid call.**')
        ->toContain('`tries = 1`, a 75-second timeout and `failOnTimeout`')
        ->toContain('provider request 45 s < job 75 s < Redis `retry_after` 90 s < worker 120 s')
        ->toContain('`translation-generation:batch:{uuid}:chunk:{id}`')
        ->toContain('expires 48 hours after the batch was created')
        ->toContain('reading never extends it')
        ->toContain('`worker_interrupted`')
        ->toContain('never from the browser')
        ->toContain('That is decided on the unit\'s locked row: `UpdateProjectTranslationAction::handleGuarded()`')
        ->toContain('a unit of `null`, and nothing else, means every ready one')
        ->toContain('never a source text, a translation, a prompt or a provider request')
        ->toContain('so no test needs a Redis server either');
});
