<?php

use Illuminate\Support\Facades\File;

/**
 * The translation engine is a reusable service, usable by any consumer: it
 * must not lean on the project-translation code that will be its first
 * consumer, on the admin UI, or on models — and it translates, it does not
 * store. These are token-level trip-wires over its source, in the spirit of
 * tests/Feature/Import/ImportArchitectureGuardTest.php: names in code are
 * caught, prose in comments is not.
 */
const TRANSLATION_ENGINE_ROOT = 'app/Support/TranslationEngine';

/**
 * Namespaces and classes the engine must not depend on, as prefixes of a
 * fully qualified name.
 */
const TRANSLATION_ENGINE_FORBIDDEN_DEPENDENCIES = [
    // The project's own translations: a consumer of the engine, never a dependency.
    'App\\Support\\Translations\\',
    'App\\Actions\\Translations\\',
    // The admin UI and its framework.
    'App\\Filament\\',
    'Filament\\',
    'App\\Livewire\\',
    'Livewire\\',
    // Models.
    'App\\Models\\',
    'Illuminate\\Database\\Eloquent\\',
];

/** What would make the engine persist, cache or defer anything. */
const TRANSLATION_ENGINE_FORBIDDEN_PERSISTENCE = [
    'Illuminate\\Support\\Facades\\DB',
    'Illuminate\\Support\\Facades\\Schema',
    'Illuminate\\Support\\Facades\\Cache',
    'Illuminate\\Support\\Facades\\Redis',
    'Illuminate\\Support\\Facades\\Queue',
    'Illuminate\\Support\\Facades\\Bus',
    'Illuminate\\Support\\Facades\\Storage',
    'Illuminate\\Support\\Facades\\Session',
    'Illuminate\\Database\\',
    'Illuminate\\Redis\\',
    'Illuminate\\Bus\\',
    'Illuminate\\Queue\\',
    'Illuminate\\Contracts\\Queue\\',
    'Illuminate\\Contracts\\Cache\\',
    'Illuminate\\Foundation\\Bus\\',
];

/** @return list<string> paths relative to the repository root */
function translationEngineFiles(): array
{
    return collect(File::allFiles(base_path(TRANSLATION_ENGINE_ROOT)))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->sort()
        ->values()
        ->all();
}

/**
 * Every class-like name a PHP source refers to — qualified names, imports and
 * class names written in strings — with comments left out entirely.
 *
 * @return list<string> fully qualified, without the leading backslash
 */
function translationEngineReferencedNames(string $source): array
{
    $names = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        [$type, $text] = $token;

        if (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $names[] = ltrim($text, '\\');
        } elseif ($type === T_CONSTANT_ENCAPSED_STRING) {
            $names[] = ltrim(str_replace('\\\\', '\\', substr($text, 1, -1)), '\\');
        }
    }

    return $names;
}

/** @return list<string> the forbidden prefixes a source refers to */
function translationEngineViolations(string $source, array $forbidden): array
{
    $violations = [];

    foreach (translationEngineReferencedNames($source) as $name) {
        foreach ($forbidden as $prefix) {
            if (strcasecmp($name, rtrim($prefix, '\\')) === 0 || str_starts_with(strtolower($name), strtolower($prefix))) {
                $violations[] = $name;
            }
        }
    }

    return array_values(array_unique($violations));
}

/** Whether a source calls dispatch() — a job or an event leaving the request. */
function translationEngineDispatches(string $source): bool
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    foreach ($tokens as $index => $token) {
        if (is_array($token) && in_array(strtolower(ltrim($token[1], '\\')), ['dispatch', 'dispatch_sync'], true)
            && ($tokens[$index + 1] ?? null) === '(') {
            return true;
        }
    }

    return false;
}

it('scans the files it exists to protect, so an empty scan cannot pass', function () {
    $files = translationEngineFiles();

    expect(count($files))->toBeGreaterThanOrEqual(20)
        ->and($files)->toContain(TRANSLATION_ENGINE_ROOT.'/TranslationService.php')
        ->and($files)->toContain(TRANSLATION_ENGINE_ROOT.'/Providers/OpenAiTranslationProvider.php');
});

it('recognises a forbidden dependency in every form a source can name it, and never in prose', function () {
    $source = <<<'PHP'
        <?php
        namespace App\Support\TranslationEngine;
        // App\Models\Comment in a comment is prose, not a dependency.
        /** @see \Livewire\Component — prose too */
        use App\Models\Post;
        use App\Models\{Comment, User};
        final class Example
        {
            public function a(): string { return \App\Filament\Pages\TranslationCenterPage::class; }
            public function b(): string { return 'App\\Support\\Translations\\ProjectTranslationUnit'; }
            public function c(): void { dispatch(new \stdClass); }
        }
        PHP;

    expect(translationEngineViolations($source, TRANSLATION_ENGINE_FORBIDDEN_DEPENDENCIES))
        // A grouped import is read as its namespace, which is itself forbidden.
        ->toBe(['App\\Models\\Post', 'App\\Models', 'App\\Filament\\Pages\\TranslationCenterPage', 'App\\Support\\Translations\\ProjectTranslationUnit'])
        ->and(translationEngineDispatches($source))->toBeTrue()
        ->and(translationEngineDispatches('<?php // dispatch(new Job) is only mentioned here'))->toBeFalse();
});

it('depends on no project translation code, admin UI, Livewire or model', function () {
    $offenders = [];

    foreach (translationEngineFiles() as $path) {
        foreach (translationEngineViolations(File::get(base_path($path)), TRANSLATION_ENGINE_FORBIDDEN_DEPENDENCIES) as $name) {
            $offenders[] = "{$path} → {$name}";
        }
    }

    expect($offenders)->toBe([], "the translation engine must stay independent of its consumers:\n".implode("\n", $offenders));
});

it('persists, caches, queues and dispatches nothing', function () {
    $offenders = [];

    foreach (translationEngineFiles() as $path) {
        $source = File::get(base_path($path));

        foreach (translationEngineViolations($source, TRANSLATION_ENGINE_FORBIDDEN_PERSISTENCE) as $name) {
            $offenders[] = "{$path} → {$name}";
        }

        if (translationEngineDispatches($source)) {
            $offenders[] = "{$path} → dispatch()";
        }
    }

    expect($offenders)->toBe([], "the translation engine returns results and stores nothing:\n".implode("\n", $offenders));
});

it('uses Laravel\'s HTTP client and no provider SDK', function () {
    $composer = json_decode(File::get(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_keys([...$composer['require'], ...$composer['require-dev']]);

    expect(array_values(array_filter($packages, fn (string $package): bool => str_contains($package, 'openai'))))->toBe([]);

    foreach (translationEngineFiles() as $path) {
        expect(translationEngineViolations(File::get(base_path($path)), ['OpenAI\\', 'GuzzleHttp\\Client']))
            ->toBe([], "{$path} must reach providers through Illuminate\\Support\\Facades\\Http");
    }
});

it('names no model anywhere in the engine — the model is configuration', function () {
    foreach (translationEngineFiles() as $path) {
        expect(phpSourceWithoutComments($path))->not->toMatch('/[\'"](gpt-|o\d-|claude-|gemini-)/i', "{$path} names a model");
    }
});

it('is reached by consumers only through TranslationService', function () {
    // Nothing outside the engine builds a provider, routes, chunks or writes a
    // prompt. Two exceptions: the service provider that registers it, and the
    // planner of background generation, which reads the configured provider's
    // name and limits to cut work into one provider request per queued job —
    // and sends nothing (asserted below).
    $sanctioned = [
        'app/Providers/TranslationEngineServiceProvider.php',
        'app/Support/Translations/Generation/ProjectTranslationGenerationPlanner.php',
    ];
    $internals = [
        'App\\Support\\TranslationEngine\\Contracts\\TranslationProvider',
        'App\\Support\\TranslationEngine\\Providers\\',
        'App\\Support\\TranslationEngine\\TranslationProviderRouter',
        'App\\Support\\TranslationEngine\\TranslationBatchChunker',
        'App\\Support\\TranslationEngine\\TranslationPromptBuilder',
    ];

    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->reject(fn (string $path): bool => str_starts_with($path, TRANSLATION_ENGINE_ROOT.'/')
            || in_array($path, $sanctioned, true))
        ->flatMap(fn (string $path): array => array_map(
            fn (string $name): string => "{$path} → {$name}",
            translationEngineViolations(File::get(base_path($path)), $internals),
        ))
        ->values()
        ->all();

    expect($offenders)->toBe([], "consumers type-hint TranslationService and nothing else:\n".implode("\n", $offenders));

    // The planner reads limits; sending stays TranslationService's alone.
    expect(phpSourceWithoutComments('app/Support/Translations/Generation/ProjectTranslationGenerationPlanner.php'))
        ->not->toContain('translateBatch')
        ->not->toContain('TranslationService');
});

it('registers the engine through its own service provider', function () {
    expect(File::get(base_path('bootstrap/providers.php')))->toContain('TranslationEngineServiceProvider::class')
        ->and(File::get(app_path('Providers/AppServiceProvider.php')))->not->toContain('TranslationEngine');
});
