<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Every line the application asks for is a stable key in one of our catalogs.
 *
 * TranslationParityTest proves the catalogs agree with each other; this proves
 * the code actually reads them. Three failures it exists for, all of them silent
 * at runtime:
 *
 *  - a prose key — `__('Remember me')` — is the English sentence itself, looked
 *    up in lang/{locale}.json. Rewording it in a view orphans every translation,
 *    and no catalog check can see it.
 *  - a key in a catalog only the framework ships (`pagination.previous`) has an
 *    English line and nothing else, so every other language falls back to it.
 *  - a public page reading the admin catalog, which exists only in English.
 *
 * Only literal keys are checked; a key built at runtime is the caller's to prove.
 */

/**
 * The translation keys a file passes as a literal, with the comments removed
 * so a sentence documenting the rule is not mistaken for a breach of it.
 *
 * @return list<string>
 */
function literalTranslationKeys(string $path): array
{
    $source = str_ends_with($path, '.blade.php')
        ? (string) preg_replace('/\{\{--.*?--\}\}/s', '', File::get($path))
        : phpSourceWithoutComments(str_replace(base_path().'/', '', $path));

    preg_match_all(
        '/(?<![\w>:$])(?:__|trans|trans_choice|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*[,)]/',
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    return collect($matches)
        ->map(fn (array $match): string => ($match[2] ?? '') !== '' ? $match[2] : $match[1])
        ->reject(fn (string $key): bool => str_contains($key, '$'))
        ->values()
        ->all();
}

/** @return iterable<SplFileInfo> */
function translatedSourceFiles(string ...$directories): iterable
{
    return Finder::create()->files()->in($directories)->name(['*.php', '*.blade.php']);
}

it('names every line with a stable key from our own catalogs', function () {
    $problems = [];

    foreach (translatedSourceFiles(app_path(), resource_path('views')) as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        foreach (literalTranslationKeys($file->getPathname()) as $key) {
            if (! preg_match('/^[a-z_]+\.[A-Za-z0-9_.]+$/', $key)) {
                $problems[] = "{$relative} uses the prose key [{$key}]";

                continue;
            }

            [$catalog, $line] = explode('.', $key, 2);
            $path = lang_path("en/{$catalog}.php");

            if (! is_file($path)) {
                $problems[] = "{$relative} reads [{$key}] from a catalog the application does not ship";
            } elseif (! Arr::has(require $path, $line)) {
                $problems[] = "{$relative} reads [{$key}], which en/{$catalog}.php does not define";
            }
        }
    }

    expect($problems)->toBe([], "translation keys outside the catalogs:\n".implode("\n", array_unique($problems)));
});

it('keeps the English-only admin catalog out of public pages', function () {
    // The admin catalog is never translated, so a public page reading it shows
    // English to every reader whatever language they chose.
    $problems = [];

    $public = translatedSourceFiles(
        app_path('Livewire'),
        app_path('Http'),
        app_path('Notifications'),
        app_path('Mail'),
        app_path('View'),
        resource_path('views'),
    );

    foreach ($public as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (str_starts_with($relative, 'resources/views/filament/')) {
            continue;
        }

        foreach (literalTranslationKeys($file->getPathname()) as $key) {
            if (str_starts_with($key, 'admin.')) {
                $problems[] = "{$relative} reads [{$key}]";
            }
        }
    }

    expect($problems)->toBe([], "public pages reading the admin catalog:\n".implode("\n", $problems));
});

it('recognises the key shapes it is guarding against', function () {
    // The guard is only as good as its pattern: a regex that silently stopped
    // matching would pass every file.
    $fixture = tempnam(sys_get_temp_dir(), 'keys').'.blade.php';
    File::put($fixture, <<<'BLADE'
        {{ __('Remember me') }}
        {{ __("Update your account's profile") }}
        @lang('auth.fields.email')
        {{ trans_choice('ui.comments.count', 2, ['count' => 2]) }}
        {{ __('sharing.'.$provider) }}
        {{ __("{$keys}.subject") }}
        {{-- __('Commented out') --}}
        BLADE);

    try {
        expect(literalTranslationKeys($fixture))->toBe([
            'Remember me',
            "Update your account's profile",
            'auth.fields.email',
            'ui.comments.count',
        ]);
    } finally {
        File::delete($fixture);
    }
});
